<?php

namespace App\Study;

use App\Platform\Access\Guard;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\Gone;
use App\Platform\Errors\NotFound;
use App\Platform\Ids;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Throwable;

/**
 * Uploaded files in a workspace (docs/specs/workspaces.md step 4). A file
 * sits at the workspace's top level, in a module or in a folder, like a
 * note. Only files that FileTypes accepts are kept, on the private files
 * disk under a random key, never under the uploaded name, and within the
 * student's quota. Trashed files go for good after TRASH_DAYS. The student's
 * own stream only: anyone else's file is 404, like a missing one.
 */
final class Files
{
    public const MAX_NAME = 200;

    public const TRASH_DAYS = 30;

    /** The largest upload that gets through: this app's limit, or PHP's if lower. */
    public static function maxBytes(): int
    {
        $ini = function (string $key): int {
            $value = trim((string) ini_get($key));
            $number = (int) $value;

            return match (strtolower(substr($value, -1))) {
                'g' => $number * 1024 ** 3,
                'm' => $number * 1024 ** 2,
                'k' => $number * 1024,
                default => $number,
            };
        };

        return min(array_filter([(int) config('vistud.files.max_bytes'), $ini('upload_max_filesize'), $ini('post_max_size')]));
    }

    /** @return list<FileDetails> the files not in the trash, in order within each place */
    public function list(Principal $by, string $workspaceId): array
    {
        $scope = Guard::learner($by);
        Input::workspace($scope, $workspaceId);

        return LearnerTables::query($scope, 'files')->where('workspace_id', $workspaceId)->whereNull('trashed_at')
            ->orderBy('position')->orderBy('id')->get()->map(fn ($row) => self::details($row))->all();
    }

    /** @return list<FileDetails> the workspace's trashed files, most recently trashed first */
    public function trashed(Principal $by, string $workspaceId): array
    {
        $scope = Guard::learner($by);
        Input::workspace($scope, $workspaceId);

        return LearnerTables::query($scope, 'files')->where('workspace_id', $workspaceId)->whereNotNull('trashed_at')
            ->orderByDesc('trashed_at')->get()->map(fn ($row) => self::details($row))->all();
    }

    public function find(Principal $by, string $id): FileDetails
    {
        return self::details($this->row(Guard::learner($by), $id));
    }

    /**
     * Checks and keeps the file at $path, uploaded as $originalName, at the
     * end of a place: `workspace` (its top level), `module` or `folder`.
     */
    public function upload(Principal $by, string $placeType, string $placeId, string $path, string $originalName): FileDetails
    {
        $scope = Guard::learner($by);
        [$name, $extension] = self::splitName($originalName);
        $size = (int) @filesize($path);
        $max = (int) config('vistud.files.max_bytes');
        Input::refuse(match (true) {
            $size === 0 => ['file' => 'This file is empty.'],
            $size > $max => ['file' => 'Files can be up to '.Number::fileSize($max).'.'],
            default => [],
        });
        [$kind, $mime] = FileTypes::inspect($path, $extension);

        $id = Ids::new();
        $key = "learners/{$scope->learnerId}/files/{$id}";
        $disk = self::disk();
        try {
            DB::transaction(function () use ($scope, $placeType, $placeId, $path, $id, $key, $disk, $name, $extension, $kind, $mime, $size) {
                [$workspaceId, $moduleId, $folderId] = Input::place($scope, $placeType, $placeId);
                $quota = (int) config('vistud.files.quota_bytes');
                if ((int) LearnerTables::query($scope, 'files')->lockForUpdate()->sum('size') + $size > $quota) {
                    throw new Conflict('quota_exceeded', 'This would go over your '.Number::fileSize($quota).' of storage. Empty the trash or delete files you no longer need.');
                }

                $stream = fopen($path, 'rb');
                try {
                    $disk->writeStream($key, $stream) ?: throw new \RuntimeException('The file could not be stored.');
                } finally {
                    fclose($stream);
                }
                LearnerTables::insert($scope, 'files', [
                    'id' => $id, 'workspace_id' => $workspaceId, 'module_id' => $moduleId, 'folder_id' => $folderId,
                    'name' => $name, 'extension' => $extension, 'kind' => $kind, 'mime' => $mime, 'size' => $size,
                    'sha256' => hash_file('sha256', $path), 'storage_key' => $key,
                    'position' => $this->nextPosition($scope, $workspaceId, $moduleId, $folderId),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            });
        } catch (Throwable $e) {
            // Nothing stored without its record.
            $disk->delete($key);
            throw $e;
        }

        return $this->find($by, $id);
    }

    /** A new name; the extension stays, since it says what the file is. */
    public function rename(Principal $by, string $id, mixed $name): void
    {
        $scope = Guard::learner($by);
        $name = Input::text(['name' => is_string($name) ? preg_replace('/[\x00-\x1F\x7F\/\\\\]/u', ' ', $name) : null], 'name');
        Input::refuse(match (true) {
            $name === null => ['name' => 'Give the file a name.'],
            mb_strlen($name) > self::MAX_NAME => ['name' => 'Keep the name to '.self::MAX_NAME.' characters.'],
            default => [],
        });
        $this->row($scope, $id);

        LearnerTables::query($scope, 'files')->where('id', $id)->update(['name' => $name, 'updated_at' => now()]);
    }

    /** Moves a file to the end of another place in the same workspace. */
    public function move(Principal $by, string $id, string $placeType, string $placeId): void
    {
        $scope = Guard::learner($by);

        DB::transaction(function () use ($scope, $id, $placeType, $placeId) {
            $file = $this->row($scope, $id, lock: true);
            [$workspaceId, $moduleId, $folderId] = Input::place($scope, $placeType, $placeId);
            if ($workspaceId !== $file->workspace_id) {
                throw new NotFound;
            }
            if ($moduleId === $file->module_id && $folderId === $file->folder_id) {
                return;
            }
            LearnerTables::query($scope, 'files')->where('id', $id)->update([
                'module_id' => $moduleId, 'folder_id' => $folderId,
                'position' => $this->nextPosition($scope, $workspaceId, $moduleId, $folderId), 'updated_at' => now(),
            ]);
        });
    }

    public function trash(Principal $by, string $id): void
    {
        $scope = Guard::learner($by);
        $this->row($scope, $id);
        LearnerTables::query($scope, 'files')->where('id', $id)->whereNull('trashed_at')->update(['trashed_at' => now()]);
    }

    /** Takes a file out of the trash, back to its place, or to the top level if its place has gone. */
    public function restore(Principal $by, string $id): FileDetails
    {
        $scope = Guard::learner($by);

        DB::transaction(function () use ($scope, $id) {
            $file = $this->row($scope, $id, lock: true);
            if ($file->trashed_at === null) {
                return;
            }
            [$moduleId, $folderId] = [$file->module_id, $file->folder_id];
            if ($folderId !== null && ! LearnerTables::query($scope, 'folders')->where('id', $folderId)->exists()) {
                $folderId = null;
            }
            if ($moduleId !== null && ! LearnerTables::query($scope, 'modules')->where('id', $moduleId)->exists()) {
                [$moduleId, $folderId] = [null, null];
            }
            LearnerTables::query($scope, 'files')->where('id', $id)->update([
                'trashed_at' => null, 'module_id' => $moduleId, 'folder_id' => $folderId,
                'position' => $this->nextPosition($scope, $file->workspace_id, $moduleId, $folderId),
            ]);
        });

        return $this->find($by, $id);
    }

    /** Deletes a file in the trash for good, its bytes included. */
    public function destroy(Principal $by, string $id): void
    {
        $scope = Guard::learner($by);
        $file = $this->row($scope, $id);
        if ($file->trashed_at === null) {
            throw new Conflict('not_trashed', 'Move the file to the trash first.');
        }
        $this->delete($scope, $file);
    }

    /** Deletes the learner's files that have been in the trash longer than TRASH_DAYS. Returns how many. */
    public function purgeTrash(LearnerScope $scope): int
    {
        $rows = LearnerTables::query($scope, 'files')->where('trashed_at', '<', now()->subDays(self::TRASH_DAYS))->get();
        foreach ($rows as $row) {
            $this->delete($scope, $row);
        }

        return $rows->count();
    }

    /**
     * What the file page and the download need: the file and where its bytes
     * are on the files disk. A trashed file can't be opened (410).
     *
     * @return array{0: FileDetails, 1: string}
     */
    public function content(Principal $by, string $id): array
    {
        $row = $this->row(Guard::learner($by), $id);
        if ($row->trashed_at !== null) {
            throw new Gone('trashed');
        }

        return [self::details($row), $row->storage_key];
    }

    /** The start of a text file, for showing on its page. Null for other kinds. */
    public function textPreview(Principal $by, string $id, int $bytes = 200_000): ?string
    {
        [$file, $key] = $this->content($by, $id);
        if ($file->kind !== 'text') {
            return null;
        }
        $stream = self::disk()->readStream($key);
        $text = (string) fread($stream, $bytes);
        fclose($stream);

        // Cut at a whole character.
        return mb_strcut($text, 0, strlen($text), 'UTF-8');
    }

    public static function disk(): Filesystem
    {
        return Storage::disk(config('vistud.files.disk'));
    }

    private function delete(LearnerScope $scope, object $row): void
    {
        LearnerTables::query($scope, 'files')->where('id', $row->id)->delete();
        self::disk()->delete($row->storage_key);
    }

    private function nextPosition(LearnerScope $scope, string $workspaceId, ?string $moduleId, ?string $folderId): int
    {
        $query = LearnerTables::query($scope, 'files')->where('workspace_id', $workspaceId);
        $query = match (true) {
            $folderId !== null => $query->where('folder_id', $folderId),
            $moduleId !== null => $query->whereNull('folder_id')->where('module_id', $moduleId),
            default => $query->whereNull('folder_id')->whereNull('module_id'),
        };

        return (int) $query->max('position') + 1;
    }

    private function row(LearnerScope $scope, string $id, bool $lock = false): object
    {
        $query = LearnerTables::query($scope, 'files')->where('id', $id);

        return ($lock ? $query->lockForUpdate() : $query)->first() ?? throw new NotFound;
    }

    /**
     * The uploaded name, split into a clean name and its extension. No
     * folders, control characters or slashes survive.
     *
     * @return array{0: string, 1: string}
     */
    private static function splitName(string $original): array
    {
        $base = str_replace('\\', '/', $original);
        $base = substr($base, (int) strrpos('/'.$base, '/'));
        $dot = strrpos($base, '.');
        $name = $dot === false ? $base : substr($base, 0, $dot);
        $name = Input::text(['name' => preg_replace('/[\x00-\x1F\x7F]/u', ' ', $name) ?? ''], 'name') ?? 'Untitled';

        return [mb_substr($name, 0, self::MAX_NAME), FileTypes::extension($base)];
    }

    private static function details(object $row): FileDetails
    {
        $time = fn ($value) => Carbon::parse($value)->utc()->format('Y-m-d\TH:i:s.up');

        return new FileDetails(
            $row->id, $row->workspace_id, $row->module_id, $row->folder_id, $row->name, $row->extension, $row->kind,
            $row->mime, (int) $row->size, (int) $row->position, $time($row->created_at),
            $row->trashed_at === null ? null : $time($row->trashed_at),
        );
    }
}
