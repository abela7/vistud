<?php

declare(strict_types=1);

namespace App\Study;

use App\Platform\Access\Guard;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Platform\Ids;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

/**
 * The audio of a class, in parts. Each part is filled one piece at a time.
 * A piece sent twice is kept once. The student must say they may record
 * before the first part starts.
 */
final class ClassRecordings
{
    /** The longest one part may grow. */
    public const MAX_PART = 500 * 1024 * 1024;

    /** Pieces in one part. A piece is a few seconds, so this covers a long class. */
    public const MAX_CHUNKS = 4000;

    /** Seconds in one piece. The browser cuts a piece this often. */
    public const PIECE_SECONDS = 5;

    /** The longest a part may be reported as. */
    public const MAX_SECONDS = 4 * 60 * 60;

    /** @var array<string, string> mime => extension */
    private const TYPES = [
        'audio/webm' => 'webm',
        'audio/mp4' => 'm4a',
        'audio/m4a' => 'm4a',
        'audio/x-m4a' => 'm4a',
        'audio/aac' => 'm4a',
        'audio/ogg' => 'ogg',
        'audio/mpeg' => 'mp3',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
    ];

    public function __construct(private ClassSessions $classes) {}

    public function allow(Principal $by, string $classId): ClassSessionDetails
    {
        $scope = Guard::learner($by);
        $this->classes->find($by, $classId);
        LearnerTables::query($scope, 'class_sessions')->where('id', $classId)->whereNull('consent_at')->update([
            'consent_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->classes->find($by, $classId);
    }

    public function start(Principal $by, string $classId): ClassRecordingDetails
    {
        $scope = Guard::learner($by);
        $class = $this->classes->find($by, $classId);
        Input::refuse($class->hasConsent() ? [] : ['consent' => 'Say you have permission to record this class.']);

        return DB::transaction(function () use ($scope, $by, $class, $classId) {
            LearnerTables::query($scope, 'class_sessions')->where('id', $classId)->lockForUpdate()->first() ?? throw new NotFound;
            $open = LearnerTables::query($scope, 'class_recordings')
                ->where('class_session_id', $classId)
                ->whereNull('finished_at')
                ->whereNull('deleted_at')
                ->orderByDesc('position')
                ->first();
            if ($open !== null && (int) $open->chunks === 0) {
                return self::details($open);
            }

            $position = (int) LearnerTables::query($scope, 'class_recordings')->where('class_session_id', $classId)->max('position') + 1;
            $id = Ids::new();
            LearnerTables::insert($scope, 'class_recordings', [
                'id' => $id,
                'workspace_id' => $class->workspaceId,
                'class_session_id' => $classId,
                'position' => $position,
                'mime' => 'audio/webm',
                'extension' => 'webm',
                'path' => "learners/{$scope->learnerId}/classes/{$classId}/{$id}",
                'bytes' => 0,
                'chunks' => 0,
                'duration_seconds' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $this->find($by, $classId, $id);
        });
    }

    /**
     * Keeps the next piece of a part. $index starts at 1. The same index
     * again changes nothing. An index that skips ahead is refused.
     */
    public function accept(Principal $by, string $classId, string $recordingId, int $index, string $sourcePath, string $mime, ?int $seconds = null): ClassRecordingDetails
    {
        $scope = Guard::learner($by);
        $this->classes->find($by, $classId);
        $size = (int) @filesize($sourcePath);
        $max = (int) config('vistud.files.max_bytes');
        Input::refuse(array_filter([
            'index' => $index < 1 || $index > self::MAX_CHUNKS ? 'That piece of the recording is not valid.' : null,
            'chunk' => $size < 1 ? 'That piece of the recording is empty.' : ($size > $max ? 'That piece is larger than '.Number::fileSize($max).'.' : null),
            'mime' => isset(self::TYPES[self::baseMime($mime)]) ? null : 'That recording is not an audio file.',
        ]));

        return DB::transaction(function () use ($scope, $by, $classId, $recordingId, $index, $sourcePath, $mime, $size, $seconds) {
            $row = LearnerTables::query($scope, 'class_recordings')->where('id', $recordingId)->lockForUpdate()->first() ?? throw new NotFound;
            ($row->class_session_id === $classId && $row->deleted_at === null) || throw new NotFound;
            if ($row->finished_at !== null) {
                throw new Conflict('recording_finished', 'This part is already finished.');
            }
            if ($index <= (int) $row->chunks) {
                return self::details($row);
            }
            if ($index !== (int) $row->chunks + 1) {
                throw new Unprocessable('validation_failed', 'Some fields need attention.', ['fields' => ['index' => ['That piece is out of order.']]]);
            }
            if ((int) $row->bytes + $size > self::MAX_PART) {
                throw new Unprocessable('validation_failed', 'Some fields need attention.', ['fields' => ['chunk' => ['This part is long enough. Stop it and start another.']]]);
            }
            $this->withinQuota($scope, $size);

            $path = (string) $row->path;
            $this->writeChunk($path, $index, $sourcePath);
            $type = self::baseMime($mime);
            LearnerTables::query($scope, 'class_recordings')->where('id', $recordingId)->update([
                'chunks' => $index,
                'bytes' => (int) $row->bytes + $size,
                'mime' => $index === 1 ? $type : $row->mime,
                'extension' => $index === 1 ? self::TYPES[$type] : $row->extension,
                // How far the take had got, so a take that never finishes still has a length.
                'duration_seconds' => $seconds === null ? (int) $row->duration_seconds : max((int) $row->duration_seconds, min($seconds, self::MAX_SECONDS)),
                'updated_at' => now(),
            ]);

            return $this->find($by, $classId, $recordingId);
        });
    }

    /**
     * Keeps an unfinished take as it is: the pieces that arrived become the
     * recording. Its length is what the browser last reported, or, when it
     * never reported one, what the pieces add up to.
     */
    public function recover(Principal $by, string $classId, string $recordingId): ClassRecordingDetails
    {
        $part = $this->find($by, $classId, $recordingId);
        if ($part->deletedAt !== null) {
            throw new NotFound;
        }
        if ($part->finishedAt !== null) {
            return $part;
        }
        $seconds = $part->durationSeconds > 0 ? $part->durationSeconds : $part->chunks * self::PIECE_SECONDS;

        return $this->finish($by, $classId, $recordingId, min($seconds, self::MAX_SECONDS));
    }

    public function finish(Principal $by, string $classId, string $recordingId, int $seconds): ClassRecordingDetails
    {
        $scope = Guard::learner($by);
        $this->classes->find($by, $classId);
        Input::refuse($seconds < 0 || $seconds > self::MAX_SECONDS ? ['duration' => 'That length is not valid.'] : []);

        $part = DB::transaction(function () use ($scope, $by, $classId, $recordingId, $seconds) {
            $row = LearnerTables::query($scope, 'class_recordings')->where('id', $recordingId)->lockForUpdate()->first() ?? throw new NotFound;
            ($row->class_session_id === $classId && $row->deleted_at === null) || throw new NotFound;
            if ($row->finished_at !== null) {
                return self::details($row);
            }
            if ((int) $row->chunks < 1 || $row->path === null) {
                throw new Unprocessable('validation_failed', 'Some fields need attention.', ['fields' => ['recording' => ['Nothing was recorded.']]]);
            }
            $path = (string) $row->path;
            $this->assemble($path, (int) $row->chunks);
            $full = Files::disk()->path($path);
            LearnerTables::query($scope, 'class_recordings')->where('id', $recordingId)->update([
                'bytes' => (int) filesize($full),
                'sha256' => hash_file('sha256', $full),
                'duration_seconds' => $seconds,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            $total = (int) LearnerTables::query($scope, 'class_recordings')
                ->where('class_session_id', $classId)
                ->whereNull('deleted_at')
                ->whereNotNull('finished_at')
                ->sum('duration_seconds');
            LearnerTables::query($scope, 'class_sessions')->where('id', $classId)->update([
                'status' => ClassSessions::RECORDED,
                'duration_seconds' => $total,
                'updated_at' => now(),
            ]);

            return $this->find($by, $classId, $recordingId);
        });

        return $part;
    }

    /** Drops an unfinished part. The row stays, without its audio. */
    public function discard(Principal $by, string $classId, string $recordingId): void
    {
        $scope = Guard::learner($by);
        $this->classes->find($by, $classId);
        $row = LearnerTables::query($scope, 'class_recordings')->where('id', $recordingId)->first() ?? throw new NotFound;
        ($row->class_session_id === $classId) || throw new NotFound;
        if ($row->deleted_at !== null) {
            return;
        }
        if ($row->finished_at !== null) {
            throw new Conflict('recording_finished', 'This part is already finished.');
        }
        if ($row->path !== null) {
            Files::disk()->delete($row->path);
            Files::disk()->deleteDirectory($row->path.'.chunks');
        }
        LearnerTables::query($scope, 'class_recordings')->where('id', $recordingId)->update([
            'path' => null,
            'bytes' => 0,
            'deleted_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return list<ClassRecordingDetails> finished parts, then an interrupted one */
    public function forClass(Principal $by, string $classId): array
    {
        $scope = Guard::learner($by);
        $this->classes->find($by, $classId);

        return LearnerTables::query($scope, 'class_recordings')
            ->where('class_session_id', $classId)
            ->whereNull('deleted_at')
            ->where(function ($query) {
                $query->whereNotNull('finished_at')->orWhere('chunks', '>', 0);
            })
            ->orderBy('position')
            ->get()
            ->map(fn (object $row) => self::details($row))
            ->all();
    }

    /**
     * Finished takes, in order, on one clock. A later take starts where the
     * previous one ended. They stay separate files: pasting a second webm
     * onto the first would not play.
     *
     * @return list<array{id: string, seconds: int, offset: int}>
     */
    public function timeline(Principal $by, string $classId): array
    {
        $tracks = [];
        $offset = 0;
        foreach ($this->forClass($by, $classId) as $part) {
            if ($part->finishedAt === null || $part->path === null) {
                continue;
            }
            $tracks[] = ['id' => $part->id, 'seconds' => $part->durationSeconds, 'offset' => $offset];
            $offset += $part->durationSeconds;
        }

        return $tracks;
    }

    /** The finished audio the student listens to. */
    public function playable(Principal $by, string $classId, string $recordingId): ClassRecordingDetails
    {
        $part = $this->find($by, $classId, $recordingId);
        if ($part->deletedAt !== null || $part->finishedAt === null || $part->path === null || ! Files::disk()->exists($part->path)) {
            throw new NotFound;
        }

        return $part;
    }

    public function find(Principal $by, string $classId, string $recordingId): ClassRecordingDetails
    {
        $scope = Guard::learner($by);
        $row = LearnerTables::query($scope, 'class_recordings')->where('id', $recordingId)->first() ?? throw new NotFound;
        $row->class_session_id === $classId || throw new NotFound;

        return self::details($row);
    }

    private function withinQuota(LearnerScope $scope, int $size): void
    {
        $quota = (int) config('vistud.files.quota_bytes');
        $used = (int) LearnerTables::query($scope, 'files')->sum('size')
            + (int) LearnerTables::query($scope, 'class_recordings')->whereNull('deleted_at')->sum('bytes');
        if ($used + $size > $quota) {
            throw new Conflict('quota_exceeded', 'This would go over your '.Number::fileSize($quota).' of storage.');
        }
    }

    private function writeChunk(string $path, int $index, string $sourcePath): void
    {
        $directory = Files::disk()->path($path.'.chunks');
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new \RuntimeException('The recording could not be stored.');
        }
        $in = fopen($sourcePath, 'rb');
        $out = fopen($directory.DIRECTORY_SEPARATOR.$index, 'wb');
        if ($in === false || $out === false) {
            if (is_resource($in)) {
                fclose($in);
            }
            if (is_resource($out)) {
                fclose($out);
            }
            throw new \RuntimeException('The recording could not be stored.');
        }
        try {
            stream_copy_to_stream($in, $out);
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    private function assemble(string $path, int $chunks): void
    {
        $disk = Files::disk();
        $target = $disk->path($path);
        $parent = dirname($target);
        if (! is_dir($parent) && ! mkdir($parent, 0775, true) && ! is_dir($parent)) {
            throw new \RuntimeException('The recording could not be stored.');
        }
        $out = fopen($target, 'wb');
        if ($out === false) {
            throw new \RuntimeException('The recording could not be stored.');
        }
        try {
            for ($i = 1; $i <= $chunks; $i++) {
                $in = fopen($disk->path($path.'.chunks/'.$i), 'rb');
                if ($in === false) {
                    throw new Unprocessable('validation_failed', 'Some fields need attention.', ['fields' => ['recording' => ['A piece of the recording is missing.']]]);
                }
                try {
                    stream_copy_to_stream($in, $out);
                } finally {
                    fclose($in);
                }
            }
        } catch (\Throwable $e) {
            fclose($out);
            $disk->delete($path);
            throw $e;
        }
        fclose($out);
        $disk->deleteDirectory($path.'.chunks');
    }

    private static function baseMime(string $mime): string
    {
        return strtolower(trim(explode(';', $mime, 2)[0]));
    }

    private static function details(object $row): ClassRecordingDetails
    {
        return new ClassRecordingDetails(
            id: $row->id,
            classSessionId: $row->class_session_id,
            position: (int) $row->position,
            mime: $row->mime,
            extension: $row->extension,
            path: $row->path,
            bytes: (int) $row->bytes,
            chunks: (int) $row->chunks,
            durationSeconds: (int) $row->duration_seconds,
            finishedAt: $row->finished_at !== null ? (string) $row->finished_at : null,
            deletedAt: $row->deleted_at !== null ? (string) $row->deleted_at : null,
        );
    }
}
