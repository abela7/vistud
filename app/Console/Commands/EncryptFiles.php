<?php

namespace App\Console\Commands;

use App\Platform\Access\LearnerScope;
use App\Platform\Database\LearnerTables;
use App\Platform\Files\FileCipher;
use App\Platform\Files\FileConversion;
use App\Platform\Files\FileIdentity;
use App\Platform\Files\PrivateFiles;
use App\Study\Files;
use App\Study\NoteImages;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Offline inventory and conversion. Never called by runtime file readers. */
final class EncryptFiles extends Command
{
    protected $signature = 'vistud:encrypt:files {--dry-run} {--verify} {--decrypt} {--backup-confirmed} {--learner=} {--limit=0} {--purge-temp-confirmed}';

    protected $description = 'Count, encrypt, verify or restore S3 file objects; offline and resumable.';

    private array $counts = [];

    private bool $dry;

    private bool $verify;

    private bool $decrypt;

    public function handle(): int
    {
        $this->dry = (bool) $this->option('dry-run');
        $this->verify = (bool) $this->option('verify');
        $this->decrypt = (bool) $this->option('decrypt');
        if (($this->dry && ($this->verify || $this->decrypt)) || ($this->verify && $this->decrypt) || (int) $this->option('limit') < 0) {
            $this->error('Choose one mode and a nonnegative limit.');

            return self::FAILURE;
        }
        if (! app()->runningUnitTests() && (! app()->isDownForMaintenance() || ((! $this->dry && ! $this->verify) && ! $this->option('backup-confirmed')))) {
            $this->error('Maintenance, stopped writers and a confirmed backup are required before conversion.');

            return self::FAILURE;
        }
        $this->counts = array_fill_keys(['objects', 'bytes', 'legacy', 'encrypted', 'changed', 'verified', 'missing', 'orphan', 'conflict', 'stages', 'markers', 'temps', 'blocked'], 0);
        if ($this->option('learner') !== null && ! DB::table('learners')->where('id', $this->option('learner'))->exists()) {
            $this->error('Unknown learner filter.');

            return self::FAILURE;
        }
        $lock = null;
        try {
            $source = Files::disk();
            $derived = PrivateFiles::disk('learners/previews/inventory');
            // Read-only modes create neither lock files nor directories.
            if (! $this->dry && ! $this->verify) {
                $source->makeDirectory('file-conversion');
                $lock = fopen($source->path('file-conversion/.lock'), 'c+b');
                if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
                    throw new RuntimeException('Conversion already running.');
                }
            }
            $scanned = 0;
            foreach ([[$source, 'source'], [$derived, 'derived']] as [$disk, $location]) {
                if ($location === 'derived' && $derived->path('') === $source->path('')) {
                    continue;
                }
                foreach ($this->paths($disk->path('learners')) as $tail) {
                    $path = 'learners/'.$tail;
                    if (preg_match('#^learners/([^/]+)/#', $path, $m) !== 1 || ($this->option('learner') !== null && $this->option('learner') !== $m[1])) {
                        continue;
                    }
                    if (str_contains($path, '/file-conversion/')) {
                        continue;
                    }
                    if ((int) $this->option('limit') > 0 && $scanned >= (int) $this->option('limit')) {
                        $this->line('Partial inventory: limit reached; repeat without a limit before reopening.');
                        $this->report();

                        return self::FAILURE;
                    }
                    $scanned++;
                    try {
                        if (! DB::table('learners')->where('id', $m[1])->exists()) {
                            $this->counts['orphan']++;
                            throw new RuntimeException('Unknown owner.');
                        }
                        $scope = LearnerScope::forJob($m[1]);
                        $scope->key('files');
                        $this->object($scope, $path, $disk, $location);
                    } catch (Throwable $error) {
                        $this->counts['orphan'] += $error instanceof \UnexpectedValueException ? 1 : 0;
                        $this->counts['blocked']++;
                    }
                }
            }
            foreach (DB::table('learners')->orderBy('id')->cursor() as $learner) {
                if ($this->option('learner') !== null && $this->option('learner') !== $learner->id) {
                    continue;
                }
                $scope = LearnerScope::forJob($learner->id);
                $this->missing($scope);
                $this->legacyTries($scope);
            }
            $this->legacyTemps();
        } catch (Throwable) {
            $this->counts['blocked']++;
        } finally {
            if (is_resource($lock)) {
                fclose($lock);
            }
        }
        $this->report();

        return $this->counts['blocked'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function object(LearnerScope $scope, string $path, mixed $disk, string $location): void
    {
        if (preg_match('/\.(?:writing|converting)-[a-f0-9]{32}$/D', $path, $stage) === 1) {
            $this->counts['stages']++;
            // An interrupted stage can only be discarded after its canonical object
            // has been independently verified. Missing canonical objects block.
            $canonical = substr($path, 0, -strlen($stage[0]));
            $row = $this->owner($scope, $canonical);
            $info = FileConversion::inspect($scope, $canonical, $disk->path($canonical));
            $this->fingerprints($scope, $canonical, $row, $info, false);
            if (! $info['encrypted'] && ! $this->decrypt) {
                throw new RuntimeException('Unverified canonical object.');
            }
            if ($this->dry || $this->verify) {
                throw new RuntimeException('Stage needs offline reconciliation.');
            }
            $probe = fopen($disk->path($path), 'rb');
            try {
                $sealed = fread($probe, 8) === FileCipher::MAGIC;
            } finally {
                fclose($probe);
            }
            if ($sealed) {
                // A complete, different authenticated candidate may be recoverable.
                // Keep it for review rather than discard a conflicting source.
                $candidate = FileConversion::inspect($scope, $canonical, $disk->path($path));
                if ($candidate['sha'] !== $info['sha'] || $candidate['size'] !== $info['size']) {
                    $this->counts['conflict']++;
                    throw new RuntimeException('Stage conflicts with canonical bytes.');
                }
            }
            $disk->delete($path);
            $this->counts['changed']++;

            return;
        }
        if (str_ends_with($path, '.failed')) {
            $base = substr($path, 0, -7);
            $this->owner($scope, $base);
            $this->counts['markers']++;
            if (str_contains($path, '/previews/')) {
                if ($this->verify && $location !== 'derived') {
                    throw new RuntimeException('Legacy marker remains.');
                }
                if (! $this->dry && ! $this->verify) {
                    // Never read legacy tool output, even on rollback.
                    $target = $this->decrypt ? Files::disk() : PrivateFiles::disk($base);
                    $target->put($path, 'conversion_failed');
                    if ($target->path($path) !== $disk->path($path)) {
                        $disk->delete($path);
                    }
                }
            }

            return;
        }
        $row = $this->owner($scope, $path);
        $targetDisk = $this->decrypt ? Files::disk() : PrivateFiles::disk($path);
        $physical = $disk->path($path);
        $target = $targetDisk->path($path);
        $info = FileConversion::inspect($scope, $path, $physical);
        $this->counts['objects']++;
        $this->counts['bytes'] += $info['size'];
        $this->counts[$info['encrypted'] ? 'encrypted' : 'legacy']++;
        $state = $this->state($scope, $path);
        if ($state !== null && ! $info['encrypted'] && ($state['done'] ?? false) && ! ($state['decrypt'] ?? false)) {
            throw new RuntimeException('Encrypted object header lost.');
        }
        if ($state !== null && ! $info['encrypted'] && ! hash_equals($state['fingerprint'], $info['fingerprint'])) {
            throw new RuntimeException('Interrupted conversion source changed.');
        }
        $this->fingerprints($scope, $path, $row, $info, false);
        if ($this->verify && (! $info['encrypted'] || $physical !== $target)) {
            throw new RuntimeException('Unconverted object.');
        }
        if ($physical !== $target && is_file($target)) {
            $other = FileConversion::inspect($scope, $path, $target);
            if ($other['sha'] !== $info['sha'] || $other['size'] !== $info['size']) {
                $this->counts['conflict']++;
                throw new RuntimeException('Copies conflict.');
            }
        }
        if ($this->dry || $this->verify) {
            $this->counts['verified'] += $info['encrypted'] ? 1 : 0;

            return;
        }
        $this->saveState($scope, $path, $info, false);
        $after = FileConversion::convert($scope, $path, $physical, $target, $this->decrypt);
        $this->fingerprints($scope, $path, $row, $after, true);
        $this->saveState($scope, $path, $after, true);
        if ($physical !== $target) {
            $disk->delete($path);
        }
        $this->counts['changed'] += ($info['encrypted'] === $this->decrypt || $physical !== $target) ? 1 : 0;
        $this->counts['verified']++;
    }

    private function owner(LearnerScope $scope, string $path): ?object
    {
        $identity = FileIdentity::at($scope, $path);
        if (str_starts_with($identity->kind, 'note-image:')) {
            return null;
        }
        if (str_starts_with($identity->kind, 'transcription:')) {
            $class = explode('/', $identity->object)[0];

            return LearnerTables::query($scope, 'class_sessions')->where('id', $class)->first() ?? throw new \UnexpectedValueException('Orphan diagnostic.');
        }
        if (str_starts_with($identity->kind, 'recording')) {
            [$class, $id] = explode('/', $identity->object);
            $row = LearnerTables::query($scope, 'class_recordings')->where('id', $id)->where('class_session_id', $class)->first() ?? throw new \UnexpectedValueException('Orphan recording.');
            if ($row->path !== 'learners/'.$scope->learnerId.'/classes/'.$class.'/'.$id || $row->deleted_at !== null || (str_contains($identity->kind, ':') && (int) explode(':', $identity->kind)[1] > (int) $row->chunks)) {
                throw new RuntimeException('Recording metadata mismatch.');
            }

            return $row;
        }
        $row = LearnerTables::query($scope, 'files')->where('id', $identity->object)->first() ?? throw new \UnexpectedValueException('Orphan file.');
        if ($row->storage_key !== 'learners/'.$scope->learnerId.'/files/'.$identity->object) {
            throw new RuntimeException('File metadata mismatch.');
        }

        return $row;
    }

    private function fingerprints(LearnerScope $scope, string $path, ?object $row, array $info, bool $write): void
    {
        $kind = FileIdentity::at($scope, $path)->kind;
        if (! in_array($kind, ['upload', 'recording'], true) || $row === null) {
            return;
        }
        $size = (int) ($kind === 'upload' ? $row->size : $row->bytes);
        if ($size !== $info['size'] || ($row->sha256 !== null && ! in_array($row->sha256, [$info['sha'], $info['fingerprint']], true))) {
            throw new RuntimeException('Source does not match recorded fingerprint or length.');
        }
        $wanted = $this->decrypt ? $info['sha'] : $info['fingerprint'];
        if ($this->verify && $row->sha256 !== $wanted) {
            throw new RuntimeException('Fingerprint not converted.');
        }
        $digests = $kind === 'upload' ? LearnerTables::query($scope, 'file_digests')->where('file_id', $row->id)->get() : [];
        foreach ($digests as $digest) {
            if (! in_array($digest->content_hash, [$info['sha'], $info['fingerprint']], true) || ($this->verify && $digest->content_hash !== $wanted)) {
                throw new RuntimeException('Digest association mismatch.');
            }
        }
        if ($write) {
            DB::transaction(function () use ($scope, $kind, $row, $wanted, $digests) {
                LearnerTables::query($scope, $kind === 'upload' ? 'files' : 'class_recordings')->where('id', $row->id)->update(['sha256' => $wanted]);
                foreach ($digests as $digest) {
                    LearnerTables::query($scope, 'file_digests')->where('id', $digest->id)->update(['content_hash' => $wanted]);
                }
            });
        }
    }

    /** Authenticated per-object receipt: identity and fingerprint, never a readable content hash. */
    private function state(LearnerScope $scope, string $path): ?array
    {
        $disk = Files::disk();
        $key = $this->stateKey($scope, $path);
        if (! $disk->exists($key)) {
            return null;
        }
        if ($disk->size($key) > 8192) {
            throw new RuntimeException('Conversion receipt exceeds its metadata limit.');
        }
        $record = json_decode(FileCipher::unseal($disk->get($key), $scope->key('files'), 'vistud:file-state:v1:'.$path), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($record) || ($record['path'] ?? null) !== $path || ! is_string($record['fingerprint'] ?? null)) {
            throw new RuntimeException('Invalid conversion receipt.');
        }

        return $record;
    }

    private function stateKey(LearnerScope $scope, string $path): string
    {
        return 'learners/'.$scope->learnerId.'/file-conversion/'.hash_hmac('sha256', $path, $scope->key('files')).'.state';
    }

    private function saveState(LearnerScope $scope, string $path, array $info, bool $done): void
    {
        $disk = Files::disk();
        $key = $this->stateKey($scope, $path);
        $disk->makeDirectory(dirname($key));
        $stage = $disk->path($key).'.pending';
        $bytes = FileCipher::seal(json_encode(['path' => $path, 'fingerprint' => $info['fingerprint'], 'size' => $info['size'], 'decrypt' => $this->decrypt, 'done' => $done], JSON_THROW_ON_ERROR), $scope->key('files'), 'vistud:file-state:v1:'.$path);
        $out = fopen($stage, 'wb');
        try {
            FileCipher::put($out, $bytes);
            if (! fflush($out) || ! fsync($out)) {
                throw new RuntimeException('Receipt flush failed.');
            }
        } finally {
            fclose($out);
        }
        @chmod($stage, 0600);
        if (! rename($stage, $disk->path($key))) {
            throw new RuntimeException('Receipt publication failed.');
        }
    }

    private function missing(LearnerScope $scope): void
    {
        foreach (['notes', 'note_versions'] as $table) {
            foreach (LearnerTables::query($scope, $table)->orderBy('id')->cursor() as $row) {
                $doc = str_replace('\\/', '/', (string) ($row->doc ?? ''));
                preg_match_all('#/notes/images/([A-Za-z0-9-]{1,64})#', $doc, $images);
                foreach (array_unique($images[1]) as $id) {
                    if (NoteImages::find($scope->learnerId, $id) === null) {
                        $this->counts['missing']++;
                        $this->counts['blocked']++;
                    }
                }
            }
        }
        foreach (LearnerTables::query($scope, 'files')->orderBy('id')->cursor() as $row) {
            if (! Files::disk()->exists($row->storage_key)) {
                $this->counts['missing']++;
                $this->counts['blocked']++;
            }
        }
        foreach (LearnerTables::query($scope, 'class_recordings')->whereNull('deleted_at')->orderBy('id')->cursor() as $row) {
            if ($row->finished_at !== null) {
                if ($row->path === null || ! Files::disk()->exists($row->path)) {
                    $this->counts['missing']++;
                    $this->counts['blocked']++;
                }
            } else {
                for ($i = 1; $i <= (int) $row->chunks; $i++) {
                    if ($row->path === null || ! Files::disk()->exists($row->path.'.chunks/'.$i)) {
                        $this->counts['missing']++;
                        $this->counts['blocked']++;
                    }
                }
            }
        }
    }

    /** Incremental traversal, no allFiles() array or symlink following. */
    private function paths(string $root, string $prefix = ''): \Generator
    {
        if (! is_dir($root)) {
            return;
        }
        foreach (new \DirectoryIterator($root) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            $name = $prefix.$entry->getFilename();
            if ($entry->isLink()) {
                throw new RuntimeException('Symlink in private inventory.');
            }
            if ($entry->isDir()) {
                yield from $this->paths($entry->getPathname(), $name.'/');
            } else {
                yield $name;
            }
        }
    }

    private function legacyTemps(): void
    {
        $roots = [Files::disk()->path('previews-work'), Files::disk()->path('exports-work'), Files::disk()->path('livewire-tmp'), storage_path('app/previews-work'), storage_path('app/exports-work'), storage_path('app/transcribe'), storage_path('app/transcribe-try')];
        foreach (array_unique($roots) as $root) {
            foreach ($this->paths($root) as $tail) {
                $this->counts['temps']++;
                if ($root === storage_path('app/transcribe-try') && str_ends_with($tail, '.md')) {
                    $known = false;
                    if ($this->dry) {
                        foreach (DB::table('learners')->orderBy('id')->cursor() as $learner) {
                            if ($this->option('learner') !== null && $this->option('learner') !== $learner->id) {
                                continue;
                            }
                            $scope = LearnerScope::forJob($learner->id);
                            foreach (LearnerTables::query($scope, 'class_sessions')->orderBy('id')->cursor() as $class) {
                                $known = $known || (str_starts_with($tail, $class->id.'-') && in_array(basename($tail), ['transcript.md', 'notes.md'], true));
                            }
                        }
                    }
                    if ($known) {
                        continue;
                    }
                    // Unresolved saved output is data, never a disposable audio temp.
                    $this->counts['blocked']++;

                    continue;
                }
                if (! $this->dry && ! $this->verify && $this->option('purge-temp-confirmed') && $this->option('learner') === null) {
                    // Dedicated old processing folders only; canonical files never live here.
                    // Backups must retain TranscribeTry output before this explicit purge.
                    if (! unlink($root.'/'.$tail)) {
                        $this->counts['blocked']++;
                    }
                } else {
                    $this->counts['blocked']++;
                }
            }
        }
    }

    private function legacyTries(LearnerScope $scope): void
    {
        $root = storage_path('app/transcribe-try');
        if (! is_dir($root)) {
            return;
        }
        foreach (LearnerTables::query($scope, 'class_sessions')->orderBy('id')->cursor() as $class) {
            foreach (new \DirectoryIterator($root) as $entry) {
                if ($entry->isLink() || ! $entry->isDir() || ! str_starts_with($entry->getFilename(), $class->id.'-')) {
                    continue;
                }
                foreach (['transcript', 'notes'] as $kind) {
                    $legacy = $entry->getPathname().'/'.$kind.'.md';
                    if (! is_file($legacy) || is_link($legacy)) {
                        continue;
                    }
                    try {
                        $id = substr(hash_hmac('sha256', $entry->getFilename(), $scope->key('files')), 0, 32);
                        $path = 'learners/'.$scope->learnerId.'/transcribe-try/'.$class->id.'/'.$id.'.'.$kind.'.md';
                        $info = FileConversion::inspect($scope, $path, $legacy);
                        $this->counts['objects']++;
                        $this->counts['legacy']++;
                        $this->counts['bytes'] += $info['size'];
                        if ($this->dry || $this->verify || $this->decrypt) {
                            // Rollback does not remove pre-existing readable diagnostic output.
                            if ($this->verify) {
                                $this->counts['blocked']++;
                            }

                            continue;
                        }
                        $this->saveState($scope, $path, $info, false);
                        $after = FileConversion::convert($scope, $path, $legacy, Files::disk()->path($path), false);
                        $this->saveState($scope, $path, $after, true);
                        if (! unlink($legacy)) {
                            throw new RuntimeException('Legacy diagnostic cleanup failed.');
                        }
                        $this->counts['changed']++;
                    } catch (Throwable) {
                        $this->counts['blocked']++;
                    }
                }
            }
        }
    }

    private function report(): void
    {
        foreach ($this->counts as $name => $count) {
            $this->line($name.': '.$count);
        }
    }
}
