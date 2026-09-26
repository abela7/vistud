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
            // The notes and files inside go with their folders.
            LearnerTables::query($scope, 'notes')->whereIn('folder_id', array_column($subtree, 'id'))->update(['module_id' => $moduleId]);
            LearnerTables::query($scope, 'files')->whereIn('folder_id', array_column($subtree, 'id'))->update(['module_id' => $moduleId]);
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

    /** Only an empty folder can go: its folders, notes and files must be moved or deleted first. What's in the trash doesn't count. */
    public function delete(Principal $by, string $id): void
    {
        $scope = Guard::learner($by);

        DB::transaction(function () use ($scope, $id) {
            $this->row($scope, $id, lock: true);
            if (LearnerTables::query($scope, 'folders')->where('parent_id', $id)->exists()
                || LearnerTables::query($scope, 'notes')->where('folder_id', $id)->whereNull('trashed_at')->exists()
                || LearnerTables::query($scope, 'files')->where('folder_id', $id)->whereNull('trashed_at')->exists()) {
                throw new Conflict('not_empty', 'Move or delete what\'s inside first.');
            }
            LearnerTables::query($scope, 'folders')->where('id', $id)->delete();
        });
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
