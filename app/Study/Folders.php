<?php

namespace App\Study;

use App\Platform\Access\Guard;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\NotFound;
use App\Platform\Ids;
use Illuminate\Support\Facades\DB;

/**
 * Folders in a workspace (docs/specs/workspaces.md, steps 2 and 3).
 * Organisation only: they never enter the journal (ADR 0003 §9.2). A folder
 * sits at the workspace's top level, in a module or in another folder, at
 * most MAX_DEPTH levels deep. The student's own stream only; anyone else's
 * folder is 404, like a missing one.
 */
final class Folders
{
    public const MAX_DEPTH = 8;

    public const MAX_NAME = 120;

    /** What can be in a folder besides its notes, files and links: what was studied in it (Phase 9). */
    public const STUDIED = ['topics', 'questions', 'flashcards', 'quizzes', 'study_sessions', 'topic_suggestions'];

    /**
     * Every folder in the workspace: parents before their children, siblings
     * in order, the modules' folders first and the top level's last.
     *
     * @return list<FolderDetails>
     */
    public function tree(Principal $by, string $workspaceId): array
    {
        $scope = Guard::learner($by);
        Input::workspace($scope, $workspaceId);

        $byParent = [];
        foreach ($this->rows($scope, $workspaceId) as $row) {
            $byParent[$row->parent_id ?? Input::placeKey($workspaceId, $row->module_id, null)][] = $row;
        }
        $modules = LearnerTables::query($scope, 'modules')->where('workspace_id', $workspaceId)->orderBy('position')->orderBy('id')->pluck('id');

        $ordered = [];
        $walk = function (string $key) use (&$walk, &$ordered, $byParent) {
            $siblings = $byParent[$key] ?? [];
            usort($siblings, fn ($a, $b) => [$a->position, $a->id] <=> [$b->position, $b->id]);
            foreach ($siblings as $row) {
                $ordered[] = self::details($row);
                $walk($row->id);
            }
        };
        foreach ($modules as $moduleId) {
            $walk("module:{$moduleId}");
        }
        $walk("workspace:{$workspaceId}");

        return $ordered;
    }

    public function find(Principal $by, string $id): FolderDetails
    {
        return self::details($this->row(Guard::learner($by), $id));
    }

    /**
     * The folder and every folder inside it, at any depth: what "in this folder" means when it is studied (docs/specs/
     * vistud-2-blueprint.md, Phase 9). The folder's own id comes first.
     *
     * @return list<string>
     */
    public function within(Principal $by, string $id): array
    {
        $scope = Guard::learner($by);

        return array_column($this->subtree($scope, $this->row($scope, $id)), 'id');
    }

    /**
     * The folders from the top of the folder's branch down to it, the folder last: "Lecture 1 + Lab 1 › Labs".
     *
     * @return list<FolderDetails>
     */
    public function path(Principal $by, string $id): array
    {
        $scope = Guard::learner($by);
        $row = $this->row($scope, $id);
        $byId = [];
        foreach ($this->rows($scope, $row->workspace_id) as $each) {
            $byId[$each->id] = $each;
        }
        $path = [self::details($row)];
        for ($parent = $row->parent_id; $parent !== null && isset($byId[$parent]); $parent = $byId[$parent]->parent_id) {
            array_unshift($path, self::details($byId[$parent]));
        }

        return $path;
    }

    /** A new folder at the end of a workspace's top level (`workspace`), a module (`module`) or another folder (`folder`). */
    public function create(Principal $by, string $parentType, string $parentId, mixed $name): FolderDetails
    {
        $scope = Guard::learner($by);
        $name = self::validatedName($name);
        $id = Ids::new();

        DB::transaction(function () use ($scope, $parentType, $parentId, $name, $id) {
            [$workspaceId, $moduleId, $parentFolder, $depth] = Input::place($scope, $parentType, $parentId);
            if ($depth > self::MAX_DEPTH) {
                throw new Conflict('too_deep', 'Folders go at most '.self::MAX_DEPTH.' levels deep.');
            }
            LearnerTables::insert($scope, 'folders', [
                'id' => $id, 'workspace_id' => $workspaceId, 'module_id' => $moduleId, 'parent_id' => $parentFolder,
                'name' => $name, 'depth' => $depth, 'position' => $this->nextPosition($scope, $workspaceId, $moduleId, $parentFolder),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return $this->find($by, $id);
    }

    /**
     * The folder at $path under a place (a workspace's top level, a module or a folder), made where it doesn't
     * exist yet: "Week 1/Lectures" finds or makes Week 1, then Lectures inside it. For an uploaded folder, whose
     * files keep the folders they were in. Returns the place the path ends at: [type, id], the place itself for an
     * empty path. Names match whatever their case (Week 1 is week 1); a folder that would go deeper than MAX_DEPTH
     * is refused (409 too_deep), and so is one whose name isn't valid (422).
     *
     * @param  list<string>  $path
     * @return array{0: string, 1: string}
     */
    public function ensurePath(Principal $by, string $placeType, string $placeId, array $path): array
    {
        $scope = Guard::learner($by);
        $names = array_map(fn ($name) => self::validatedName($name), $path);

        return DB::transaction(function () use ($scope, $placeType, $placeId, $names) {
            [$type, $id] = [$placeType, $placeId];
            foreach ($names as $name) {
                [$workspaceId, $moduleId, $parentFolder, $depth] = Input::place($scope, $type, $id);
                $existing = $this->siblings($scope, $workspaceId, $moduleId, $parentFolder)
                    ->first(fn ($folder) => mb_strtolower($folder->name) === mb_strtolower($name))?->id;
                if ($existing === null) {
                    if ($depth > self::MAX_DEPTH) {
                        throw new Conflict('too_deep', 'Folders go at most '.self::MAX_DEPTH.' levels deep.');
                    }
                    $existing = Ids::new();
                    LearnerTables::insert($scope, 'folders', [
                        'id' => $existing, 'workspace_id' => $workspaceId, 'module_id' => $moduleId, 'parent_id' => $parentFolder,
                        'name' => $name, 'depth' => $depth, 'position' => $this->nextPosition($scope, $workspaceId, $moduleId, $parentFolder),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                [$type, $id] = ['folder', (string) $existing];
            }

            return [$type, $id];
        });
    }

    public function rename(Principal $by, string $id, mixed $name): void
    {
        $scope = Guard::learner($by);
        $name = self::validatedName($name);
        $this->row($scope, $id);

        LearnerTables::query($scope, 'folders')->where('id', $id)->update(['name' => $name, 'updated_at' => now()]);
    }

    /**
     * Moves a folder, with everything inside it, to the end of another place
     * in the same workspace.
     */
    public function move(Principal $by, string $id, string $parentType, string $parentId): void
    {
        $scope = Guard::learner($by);

        DB::transaction(function () use ($scope, $id, $parentType, $parentId) {
            $folder = $this->row($scope, $id, lock: true);
            [$workspaceId, $moduleId, $parentFolder, $depth] = Input::place($scope, $parentType, $parentId);
            if ($workspaceId !== $folder->workspace_id) {
                throw new NotFound;
            }
            if ($parentFolder === $folder->parent_id && $moduleId === $folder->module_id) {
                return;
            }

            $subtree = $this->subtree($scope, $folder);
            if ($parentFolder !== null && in_array($parentFolder, array_column($subtree, 'id'), true)) {
                throw new Conflict('invalid_move', 'A folder can\'t go inside itself.');
            }
            $height = max(array_map(fn ($row) => (int) $row->depth, $subtree)) - (int) $folder->depth;
            if ($depth + $height > self::MAX_DEPTH) {
                throw new Conflict('too_deep', 'Folders go at most '.self::MAX_DEPTH.' levels deep.');
            }

            $shift = $depth - (int) $folder->depth;
            LearnerTables::query($scope, 'folders')->where('id', $id)->update([
                'parent_id' => $parentFolder, 'position' => $this->nextPosition($scope, $workspaceId, $moduleId, $parentFolder), 'updated_at' => now(),
            ]);
            foreach ($subtree as $row) {
                LearnerTables::query($scope, 'folders')->where('id', $row->id)->update(['module_id' => $moduleId, 'depth' => (int) $row->depth + $shift]);
            }
            // The notes, files and links inside go with their folders.
            $ids = array_column($subtree, 'id');
            LearnerTables::query($scope, 'notes')->whereIn('folder_id', $ids)->update(['module_id' => $moduleId]);
            LearnerTables::query($scope, 'files')->whereIn('folder_id', $ids)->update(['module_id' => $moduleId]);
            LearnerTables::query($scope, 'links')->whereIn('folder_id', $ids)->update(['module_id' => $moduleId]);
            if ($moduleId !== $folder->module_id) {
                $this->carry($scope, $ids, $moduleId);
            }
        });
    }

    /** Moves a folder to $position (0 is first) among its siblings. */
    public function reorder(Principal $by, string $id, int $position): void
    {
        $scope = Guard::learner($by);

        DB::transaction(function () use ($scope, $id, $position) {
            $folder = $this->row($scope, $id, lock: true);
            $ids = $this->siblings($scope, $folder->workspace_id, $folder->module_id, $folder->parent_id)->pluck('id')->all();
            $ids = array_values(array_diff($ids, [$id]));
            array_splice($ids, max(0, min($position, count($ids))), 0, [$id]);
            foreach ($ids as $index => $folderId) {
                LearnerTables::query($scope, 'folders')->where('id', $folderId)->update(['position' => $index + 1]);
            }
        });
    }

    /** Only an empty folder can go: its folders, notes, files and links must be moved or deleted first. What's in the trash doesn't count. */
    public function delete(Principal $by, string $id): void
    {
        $scope = Guard::learner($by);

        DB::transaction(function () use ($scope, $id) {
            $folder = $this->row($scope, $id, lock: true);
            if (LearnerTables::query($scope, 'folders')->where('parent_id', $id)->exists()
                || LearnerTables::query($scope, 'notes')->where('folder_id', $id)->whereNull('trashed_at')->exists()
                || LearnerTables::query($scope, 'files')->where('folder_id', $id)->whereNull('trashed_at')->exists()
                || LearnerTables::query($scope, 'links')->where('folder_id', $id)->exists()) {
                throw new Conflict('not_empty', 'Move or delete what\'s inside first.');
            }
            // What was studied in it stays, where the folder was: in the folder around it, or in the module.
            foreach (self::STUDIED as $table) {
                LearnerTables::query($scope, $table)->where('folder_id', $id)->update(['folder_id' => $folder->parent_id]);
            }
            LearnerTables::query($scope, 'folder_briefs')->where('folder_id', $id)->delete();
            LearnerTables::query($scope, 'folders')->where('id', $id)->delete();
        });
    }

    /**
     * What was studied in these folders goes with them to another module (or none). A topic the reader found waits in
     * one module once: where the new module already has it, the moved one goes.
     *
     * @param  list<string>  $ids
     */
    private function carry(LearnerScope $scope, array $ids, ?string $moduleId): void
    {
        foreach (self::STUDIED as $table) {
            if ($table === 'topic_suggestions') {
                continue;
            }
            LearnerTables::query($scope, $table)->whereIn('folder_id', $ids)->update(['module_id' => $moduleId]);
        }
        $suggested = LearnerTables::query($scope, 'topic_suggestions')->whereIn('folder_id', $ids)->get();
        foreach ($suggested as $row) {
            $there = $moduleId !== null && LearnerTables::query($scope, 'topic_suggestions')->where('module_id', $moduleId)->where('name_key', $row->name_key)->exists();
            $there || $moduleId === null
                ? LearnerTables::query($scope, 'topic_suggestions')->where('id', $row->id)->delete()
                : LearnerTables::query($scope, 'topic_suggestions')->where('id', $row->id)->update(['module_id' => $moduleId]);
        }
    }

    /** @return list<object> the folder and everything inside it */
    private function subtree(LearnerScope $scope, object $folder): array
    {
        $all = $this->rows($scope, $folder->workspace_id);
        $inside = [$folder->id => true];
        $result = [$folder];
        do {
            $added = false;
            foreach ($all as $row) {
                if ($row->parent_id !== null && isset($inside[$row->parent_id]) && ! isset($inside[$row->id])) {
                    $inside[$row->id] = true;
                    $result[] = $row;
                    $added = true;
                }
            }
        } while ($added);

        return $result;
    }

    private function siblings(LearnerScope $scope, string $workspaceId, ?string $moduleId, ?string $parentId)
    {
        $query = LearnerTables::query($scope, 'folders')->where('workspace_id', $workspaceId);
        $query = match (true) {
            $parentId !== null => $query->where('parent_id', $parentId),
            $moduleId !== null => $query->whereNull('parent_id')->where('module_id', $moduleId),
            default => $query->whereNull('parent_id')->whereNull('module_id'),
        };

        return $query->orderBy('position')->orderBy('id')->get();
    }

    private function nextPosition(LearnerScope $scope, string $workspaceId, ?string $moduleId, ?string $parentId): int
    {
        return (int) $this->siblings($scope, $workspaceId, $moduleId, $parentId)->max('position') + 1;
    }

    /** @return list<object> */
    private function rows(LearnerScope $scope, string $workspaceId): array
    {
        return LearnerTables::query($scope, 'folders')->where('workspace_id', $workspaceId)->get()->all();
    }

    private function row(LearnerScope $scope, string $id, bool $lock = false): object
    {
        $query = LearnerTables::query($scope, 'folders')->where('id', $id);

        return ($lock ? $query->lockForUpdate() : $query)->first() ?? throw new NotFound;
    }

    private static function validatedName(mixed $name): string
    {
        $name = Input::text(['name' => $name], 'name');
        Input::refuse(array_filter([
            'name' => match (true) {
                $name === null => 'Give the folder a name.',
                mb_strlen($name) > self::MAX_NAME => 'Keep the name to '.self::MAX_NAME.' characters.',
                default => null,
            },
        ]));

        return $name;
    }

    private static function details(object $row): FolderDetails
    {
        return new FolderDetails($row->id, $row->workspace_id, $row->module_id, $row->parent_id, $row->name, (int) $row->depth, (int) $row->position);
    }
}
