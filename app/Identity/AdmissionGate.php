<?php

namespace App\Identity;

use App\Engine\Settings;
use App\Platform\Access\LearnerKeyStore;
use App\Platform\Access\MasterKeys;
use App\Platform\Access\Principal;
use App\Platform\Access\Role;
use App\Platform\Database\ContentColumns;
use App\Platform\Database\LearnerTables;
use App\Platform\Files\FileCipher;
use App\Platform\Ids;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Whether a second person may join. Every check reads the installation.
 * None of them writes to the database.
 */
final class AdmissionGate
{
    public function __construct(private MasterKeys $masters, private JoiningPolicy $policy) {}

    /** @return list<string> one line per check, then result: open or closed */
    public function lines(): array
    {
        $checks = $this->checks();
        $lines = [];
        foreach ($checks as $label => $passed) {
            $lines[] = $label.': '.($passed ? 'yes' : 'no');
        }
        $lines[] = 'result: '.(! in_array(false, $checks, true) ? 'open' : 'closed');

        return $lines;
    }

    public function open(): bool
    {
        return ! in_array(false, $this->checks(), true);
    }

    /** @return array<string, bool> */
    public function checks(): array
    {
        return [
            'master key loads and a learner key wraps' => $this->masterKeyWraps(),
            'key database is separate' => $this->keyDatabaseSeparate(),
            'learner text is encrypted' => $this->textEncrypted(),
            'files are encrypted' => $this->filesEncrypted(),
            'student uses own AI key only' => $this->ownKeyOnly(),
            'acceptance run matches this commit' => $this->acceptanceMatches(),
            'privacy policy is reviewed' => $this->policyReviewed(),
        ];
    }

    /** Wrap and unwrap a throwaway learner key with the master. Nothing is stored. */
    public function masterKeyWraps(): bool
    {
        try {
            $ring = $this->masters->ring();
            $master = array_key_first($ring);
            if (! is_string($master)) {
                return false;
            }
            $learnerId = '00000000-0000-4000-8000-000000000000';
            $key = random_bytes(32);
            $payload = json_encode([
                'learner_id' => $learnerId, 'version' => LearnerKeyStore::VERSION,
                'master_version' => $master, 'key' => base64_encode($key),
            ], JSON_THROW_ON_ERROR);
            $opened = json_decode($ring[$master]->decryptString($ring[$master]->encryptString($payload)), true, flags: JSON_THROW_ON_ERROR);
            $raw = is_array($opened) && is_string($opened['key'] ?? null) ? base64_decode($opened['key'], true) : false;

            return $raw !== false && hash_equals($key, $raw) && ($opened['learner_id'] ?? null) === $learnerId;
        } catch (Throwable) {
            return false;
        }
    }

    public function keyDatabaseSeparate(): bool
    {
        try {
            LearnerTables::assertKeyDatabase();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** Count only. A row whose content_version is not current is still plain or half-converted. */
    public function textEncrypted(): bool
    {
        try {
            foreach (array_keys(ContentColumns::all()) as $table) {
                if (DB::table($table)->where('content_version', '!=', ContentColumns::VERSION)->count() > 0) {
                    return false;
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Every stored student file starts with the encrypted-file marker. Reads the first bytes of each file and
     * writes nothing, so it also works on a running site. A half-written stage or a leftover diagnostic fails it.
     */
    public function filesEncrypted(): bool
    {
        try {
            foreach (array_unique([config('vistud.files.disk'), config('vistud.files.derived_disk')]) as $name) {
                $disk = Storage::disk($name);
                foreach ($disk->allFiles('learners') as $path) {
                    $handle = $disk->readStream($path);
                    $head = is_resource($handle) ? (string) fread($handle, strlen(FileCipher::MAGIC)) : '';
                    if (is_resource($handle)) {
                        fclose($handle);
                    }
                    if ($head !== FileCipher::MAGIC) {
                        return false;
                    }
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** A non-admin with no key of their own. Tests may pass another principal to show the failure. */
    public function ownKeyOnly(?Principal $student = null): bool
    {
        $student ??= new Principal(Ids::new(), Ids::new(), [Role::Student], true, null, 'console');

        try {
            return app(Settings::class)->credential($student) === '';
        } catch (Throwable) {
            return false;
        }
    }

    public function acceptanceMatches(): bool
    {
        $path = $this->acceptancePath();
        if (! is_file($path)) {
            return false;
        }
        try {
            $record = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return false;
        }
        $head = $this->head();

        return is_array($record) && $head !== null && ($record['commit'] ?? null) === $head && ($record['passed'] ?? null) === true;
    }

    public function policyReviewed(): bool
    {
        $text = $this->policy->text();
        if (! is_string($text) || trim($text) === '') {
            return false;
        }
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (str_contains($line, 'DRAFT')) {
                return false;
            }
        }

        return preg_match('/^Reviewed by: [^,\r\n]+, \d{4}-\d{2}-\d{2}\s*$/m', $text) === 1;
    }

    public function head(): ?string
    {
        $process = new Process(['git', 'rev-parse', 'HEAD'], base_path());
        $process->run();
        $hash = trim($process->getOutput());

        return $process->isSuccessful() && preg_match('/\A[0-9a-f]{40}\z/', $hash) === 1 ? $hash : null;
    }

    public function acceptancePath(): string
    {
        $path = config('vistud.gate.acceptance');

        return is_string($path) && $path !== '' ? $path : storage_path('app/gate/acceptance.json');
    }
}
