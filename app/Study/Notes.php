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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Notes in a workspace (docs/specs/workspaces.md step 3, ADR 0003 §5 and
 * §9.1). A note sits at the workspace's top level, in a module or in a
 * folder. Every accepted save is a new version that never changes; a save
 * names the version it was based on, so newer work elsewhere is never
 * silently overwritten (409), and a retried save answers the same (save_id).
 * Trashed notes refuse saves (410) and are deleted after TRASH_DAYS. The
 * student's own stream only: anyone else's note is 404, like a missing one.
 */
final class Notes
{
    public const MAX_TITLE = 200;

    public const TRASH_DAYS = 30;

    private const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /** @return list<NoteDetails> the notes not in the trash, in order within each place */
    public function list(Principal $by, string $workspaceId): array
    {
        $scope = Guard::learner($by);
        Input::workspace($scope, $workspaceId);

        return LearnerTables::query($scope, 'notes')->where('workspace_id', $workspaceId)->whereNull('trashed_at')
            ->orderBy('position')->orderBy('id')->get()->map(fn ($row) => self::details($row))->all();
    }

    /** @return list<NoteDetails> the workspace's trash, most recently trashed first */
    public function trashed(Principal $by, string $workspaceId): array
    {
        $scope = Guard::learner($by);
        Input::workspace($scope, $workspaceId);

        return LearnerTables::query($scope, 'notes')->where('workspace_id', $workspaceId)->whereNotNull('trashed_at')
            ->orderByDesc('trashed_at')->get()->map(fn ($row) => self::details($row))->all();
    }

    /** The note without its document, trashed or not. */
    public function find(Principal $by, string $id): NoteDetails
    {
        return self::details($this->row(Guard::learner($by), $id));
    }

    /** The note with its current document, trashed or not. */
    public function open(Principal $by, string $id): NoteDetails
    {
        $scope = Guard::learner($by);
        $row = $this->row($scope, $id);
        $doc = LearnerTables::query($scope, 'note_versions')->where('note_id', $id)->where('version', $row->current_version)->value('doc');

        return self::details($row, json_decode((string) $doc, true, 512, JSON_THROW_ON_ERROR));
    }

    /** A new, empty note at the end of a place: `workspace` (its top level), `module` or `folder`. */
    public function create(Principal $by, string $placeType, string $placeId, mixed $title = ''): NoteDetails
    {
        $scope = Guard::learner($by);
        $title = self::validatedTitle($title);
        $id = Ids::new();

        DB::transaction(function () use ($scope, $placeType, $placeId, $title, $id) {
            [$workspaceId, $moduleId, $folderId] = Input::place($scope, $placeType, $placeId);
            LearnerTables::insert($scope, 'notes', [
                'id' => $id, 'workspace_id' => $workspaceId, 'module_id' => $moduleId, 'folder_id' => $folderId,
                'title' => $title, 'current_version' => 1, 'position' => $this->nextPosition($scope, $workspaceId, $moduleId, $folderId),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->addVersion($scope, $id, 1, $title, NoteDoc::empty(), null, null, null, 'created');
        });

        return $this->find($by, $id);
    }

    /**
     * Saves the editor's document and title as the next version.
     *
     * @param  array{base_version?: mixed, save_id?: mixed, client_id?: mixed, title?: mixed, doc?: mixed, kind?: mixed}  $input
     */
    public function save(Principal $by, string $id, array $input): SavedVersion
    {
        $scope = Guard::learner($by);
        $token = fn (string $key) => is_string($input[$key] ?? null) && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $input[$key]) ? $input[$key] : null;
        [$saveId, $clientId] = [$token('save_id'), $token('client_id')];
        $base = $input['base_version'] ?? null;
        $kind = $input['kind'] ?? 'autosave';
        Input::refuse(array_filter([
            'base_version' => is_int($base) && $base >= 1 ? null : 'Send the version this change is based on.',
            'save_id' => $saveId === null ? 'Send an ID for this save.' : null,
            'client_id' => $clientId === null ? 'Send an ID for this tab.' : null,
            'kind' => in_array($kind, ['autosave', 'conflict_resolution'], true) ? null : 'Unknown kind of save.',
        ]));
        $title = self::validatedTitle($input['title'] ?? '');
        $doc = NoteDoc::clean($input['doc'] ?? null);

        return DB::transaction(function () use ($scope, $id, $base, $saveId, $clientId, $kind, $title, $doc) {
            $note = $this->row($scope, $id, lock: true);

            // A retry of a save that already went through gets the same answer.
            $earlier = LearnerTables::query($scope, 'note_versions')->where('note_id', $id)->where('save_id', $saveId)->first();
            if ($earlier !== null) {
                return new SavedVersion((int) $earlier->version, self::time($earlier->created_at));
            }
            if ($note->trashed_at !== null) {
                throw new Gone('trashed');
            }
            if ($base !== (int) $note->current_version) {
                throw new Conflict('version_conflict', 'This note was changed somewhere else.', ['current_version' => (int) $note->current_version]);
            }

            $current = LearnerTables::query($scope, 'note_versions')->where('note_id', $id)->where('version', $note->current_version)->first();
            if ($current !== null && $current->title === $title && $current->doc === json_encode($doc, self::JSON)) {
                return new SavedVersion((int) $note->current_version, self::time($note->updated_at));
            }

            $version = (int) $note->current_version + 1;
            $at = now();
            $this->addVersion($scope, $id, $version, $title, $doc, $base, $saveId, $clientId, $kind, $at);
            LearnerTables::query($scope, 'notes')->where('id', $id)->update(['current_version' => $version, 'title' => $title, 'updated_at' => $at]);

            return new SavedVersion($version, self::time($at));
        });
    }

    /** Moves a note to the end of another place in the same workspace. */
    public function move(Principal $by, string $id, string $placeType, string $placeId): void
    {
        $scope = Guard::learner($by);

        DB::transaction(function () use ($scope, $id, $placeType, $placeId) {
            $note = $this->row($scope, $id, lock: true);
            [$workspaceId, $moduleId, $folderId] = Input::place($scope, $placeType, $placeId);
            if ($workspaceId !== $note->workspace_id) {
                throw new NotFound;
            }
            if ($moduleId === $note->module_id && $folderId === $note->folder_id) {
                return;
            }
            LearnerTables::query($scope, 'notes')->where('id', $id)->update([
                'module_id' => $moduleId, 'folder_id' => $folderId,
                'position' => $this->nextPosition($scope, $workspaceId, $moduleId, $folderId),
            ]);
        });
    }

    /** Moves a note to the trash. It can be restored for TRASH_DAYS. */
    public function trash(Principal $by, string $id): void
    {
        $scope = Guard::learner($by);

        DB::transaction(function () use ($scope, $id) {
            $note = $this->row($scope, $id, lock: true);
            if ($note->trashed_at === null) {
                LearnerTables::query($scope, 'notes')->where('id', $id)->update(['trashed_at' => now()]);
                $this->tombstone($scope, $id, 'trashed');
            }
        });
    }

    /** Takes a note out of the trash, back to its place, or to the top level if its place has gone. */
    public function restore(Principal $by, string $id): NoteDetails
    {
        $scope = Guard::learner($by);

        DB::transaction(function () use ($scope, $id) {
            $note = $this->row($scope, $id, lock: true);
            if ($note->trashed_at === null) {
                return;
            }
            [$moduleId, $folderId] = [$note->module_id, $note->folder_id];
            if ($folderId !== null && ! LearnerTables::query($scope, 'folders')->where('id', $folderId)->exists()) {
                $folderId = null;
            }
            if ($moduleId !== null && ! LearnerTables::query($scope, 'modules')->where('id', $moduleId)->exists()) {
                [$moduleId, $folderId] = [null, null];
            }
            LearnerTables::query($scope, 'notes')->where('id', $id)->update([
                'trashed_at' => null, 'module_id' => $moduleId, 'folder_id' => $folderId,
                'position' => $this->nextPosition($scope, $note->workspace_id, $moduleId, $folderId),
            ]);
            $this->tombstone($scope, $id, 'restored');
        });

        return $this->find($by, $id);
    }

    /** Deletes a note in the trash for good, with every version. */
    public function destroy(Principal $by, string $id): void
    {
        $scope = Guard::learner($by);

        DB::transaction(function () use ($scope, $id) {
            if ($this->row($scope, $id, lock: true)->trashed_at === null) {
                throw new Conflict('not_trashed', 'Move the note to the trash first.');
            }
            $this->delete($scope, $id);
        });
    }

    /** Deletes the learner's notes that have been in the trash longer than TRASH_DAYS. Returns how many. */
    public function purgeTrash(LearnerScope $scope): int
    {
        $ids = LearnerTables::query($scope, 'notes')->where('trashed_at', '<', now()->subDays(self::TRASH_DAYS))->pluck('id');
        foreach ($ids as $id) {
            DB::transaction(fn () => $this->delete($scope, $id));
        }

        return $ids->count();
    }

    private function delete(LearnerScope $scope, string $id): void
    {
        LearnerTables::query($scope, 'note_versions')->where('note_id', $id)->delete();
        LearnerTables::query($scope, 'notes')->where('id', $id)->delete();
        $this->tombstone($scope, $id, 'deleted');
    }

    private function addVersion(LearnerScope $scope, string $noteId, int $version, string $title, array $doc, ?int $base, ?string $saveId, ?string $clientId, string $kind, $at = null): void
    {
        LearnerTables::insert($scope, 'note_versions', [
            'id' => Ids::new(), 'note_id' => $noteId, 'version' => $version, 'title' => $title,
            'doc' => json_encode($doc, self::JSON), 'base_version' => $base, 'save_id' => $saveId,
            'client_id' => $clientId, 'kind' => $kind, 'created_at' => $at ?? now(),
        ]);
    }

    private function tombstone(LearnerScope $scope, string $noteId, string $kind): void
    {
        LearnerTables::insert($scope, 'content_tombstones', ['entity_type' => 'note', 'entity_id' => $noteId, 'kind' => $kind, 'at' => now()]);
    }

    private function nextPosition(LearnerScope $scope, string $workspaceId, ?string $moduleId, ?string $folderId): int
    {
        $query = LearnerTables::query($scope, 'notes')->where('workspace_id', $workspaceId);
        $query = match (true) {
            $folderId !== null => $query->where('folder_id', $folderId),
            $moduleId !== null => $query->whereNull('folder_id')->where('module_id', $moduleId),
            default => $query->whereNull('folder_id')->whereNull('module_id'),
        };

        return (int) $query->max('position') + 1;
    }

    private function row(LearnerScope $scope, string $id, bool $lock = false): object
    {
        $query = LearnerTables::query($scope, 'notes')->where('id', $id);

        return ($lock ? $query->lockForUpdate() : $query)->first() ?? throw new NotFound;
    }

    private static function validatedTitle(mixed $title): string
    {
        $title = Input::text(['title' => $title], 'title') ?? '';
        Input::refuse(mb_strlen($title) > self::MAX_TITLE ? ['title' => 'Keep the title to '.self::MAX_TITLE.' characters.'] : []);

        return $title;
    }

    /** A stored time as ISO 8601 with microseconds, in UTC. */
    private static function time(mixed $value): string
    {
        return Carbon::parse($value)->utc()->format('Y-m-d\TH:i:s.up');
    }

    private static function details(object $row, ?array $doc = null): NoteDetails
    {
        return new NoteDetails(
            $row->id, $row->workspace_id, $row->module_id, $row->folder_id, $row->title,
            (int) $row->current_version, (int) $row->position, self::time($row->updated_at),
            $row->trashed_at === null ? null : self::time($row->trashed_at), $doc,
        );
    }
}
