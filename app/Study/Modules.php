<?php

namespace App\Study;

use App\Brain\Writer\JournalWriter;
use App\Platform\Access\Guard;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\NotFound;
use App\Platform\Ids;
use Illuminate\Support\Facades\DB;

/**
 * A workspace's modules, in order (docs/specs/workspaces.md, step 2). The
 * student's own stream only: another student's workspace or module is 404,
 * like a missing one. Every change is also a new revision of the module's
 * `module` journal record (ADR 0002), so evidence can name the unit.
 */
final class Modules
{
    public const MAX_TITLE = 120;

    public function __construct(private JournalWriter $journal) {}

    /** @return list<ModuleDetails> in order */
    public function list(Principal $by, string $workspaceId): array
    {
        $scope = Guard::learner($by);
        Input::workspace($scope, $workspaceId);

        return LearnerTables::query($scope, 'modules')->where('workspace_id', $workspaceId)
            ->orderBy('position')->orderBy('id')->get()->map(self::details(...))->all();
    }

    public function find(Principal $by, string $id): ModuleDetails
    {
        $row = LearnerTables::query(Guard::learner($by), 'modules')->where('id', $id)->first();

        return $row === null ? throw new NotFound : self::details($row);
    }

    /** @param array{title?: mixed, starts_on?: mixed, ends_on?: mixed} $input */
    public function create(Principal $by, string $workspaceId, array $input): ModuleDetails
    {
        $scope = Guard::learner($by);
        $fields = self::validated($input);
        $id = Ids::new();

        DB::transaction(function () use ($scope, $workspaceId, $fields, $id) {
            Input::workspace($scope, $workspaceId, lock: true);
            $position = (int) LearnerTables::query($scope, 'modules')->where('workspace_id', $workspaceId)->max('position') + 1;
            LearnerTables::insert($scope, 'modules', $fields + [
                'id' => $id, 'workspace_id' => $workspaceId, 'position' => $position, 'revision' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->record($scope, $this->lock($scope, $id));
        });

        return $this->find($by, $id);
    }

    public function update(Principal $by, string $id, array $input): ModuleDetails
    {
        $scope = Guard::learner($by);
        $fields = self::validated($input);

        DB::transaction(function () use ($scope, $id, $fields) {
            $row = $this->lock($scope, $id);
            LearnerTables::query($scope, 'modules')->where('id', $id)->update($fields + ['revision' => $row->revision + 1, 'updated_at' => now()]);
            $this->record($scope, $this->lock($scope, $id));
        });

        return $this->find($by, $id);
    }

    /** Moves the module to $position (0 is first) among its workspace's modules. */
    public function move(Principal $by, string $id, int $position): void
    {
        $scope = Guard::learner($by);

        DB::transaction(function () use ($scope, $id, $position) {
            $moved = $this->lock($scope, $id);
            $order = LearnerTables::query($scope, 'modules')->where('workspace_id', $moved->workspace_id)
                ->orderBy('position')->orderBy('id')->lockForUpdate()->pluck('position', 'id')->all();

            $ids = array_values(array_diff(array_keys($order), [$id]));
            array_splice($ids, max(0, min($position, count($ids))), 0, [$id]);

            foreach ($ids as $index => $moduleId) {
                if ((int) $order[$moduleId] !== $index + 1) {
                    LearnerTables::query($scope, 'modules')->where('id', $moduleId)
                        ->update(['position' => $index + 1, 'revision' => DB::raw('revision + 1'), 'updated_at' => now()]);
                    $this->record($scope, $this->lock($scope, $moduleId));
                }
            }
        });
    }

    /** Only an empty module can go: its folders, notes and files must be moved or deleted first. What's in the trash doesn't count. */
    public function delete(Principal $by, string $id): void
    {
        $scope = Guard::learner($by);

        DB::transaction(function () use ($scope, $id) {
            $row = $this->lock($scope, $id);
            if (LearnerTables::query($scope, 'folders')->where('module_id', $id)->exists()
                || LearnerTables::query($scope, 'notes')->where('module_id', $id)->whereNull('trashed_at')->exists()
                || LearnerTables::query($scope, 'files')->where('module_id', $id)->whereNull('trashed_at')->exists()) {
                throw new Conflict('not_empty', 'Move or delete what\'s inside first.');
            }
            LearnerTables::query($scope, 'modules')->where('id', $id)->delete();
            $row->revision++;
            $this->record($scope, $row, deleted: true);
        });
    }

    private function lock(LearnerScope $scope, string $id): object
    {
        return LearnerTables::query($scope, 'modules')->where('id', $id)->lockForUpdate()->first() ?? throw new NotFound;
    }

    /** A new revision of the module's journal record, with its current details. */
    private function record(LearnerScope $scope, object $row, bool $deleted = false): void
    {
        $this->journal->append($scope, [
            'id' => Ids::new(),
            'kind' => 'record',
            'actor' => ['type' => 'learner', 'id' => $scope->learnerId, 'channel' => 'web'],
            'occurred_at' => now()->toIso8601String(),
            'body' => array_filter([
                'record_type' => 'module',
                'record_id' => $row->id,
                'revision' => (int) $row->revision,
                'workspace' => $row->workspace_id,
                'title' => $row->title,
                'position' => (int) $row->position,
                'starts_on' => $row->starts_on,
                'ends_on' => $row->ends_on,
                'status' => $deleted ? 'deleted' : 'active',
            ], fn ($value) => $value !== null),
        ]);
    }

    /** @return array{title: string, starts_on: ?string, ends_on: ?string} */
    private static function validated(array $input): array
    {
        $title = Input::text($input, 'title');
        [$startsOn, $endsOn, $dateErrors] = Input::dates($input);

        Input::refuse(array_filter([
            'title' => match (true) {
                $title === null => 'Give the module a title.',
                mb_strlen($title) > self::MAX_TITLE => 'Keep the title to '.self::MAX_TITLE.' characters.',
                default => null,
            },
            ...$dateErrors,
        ]));

        return ['title' => $title, 'starts_on' => $startsOn, 'ends_on' => $endsOn];
    }

    private static function details(object $row): ModuleDetails
    {
        return new ModuleDetails($row->id, $row->workspace_id, $row->title, $row->starts_on, $row->ends_on, (int) $row->position);
    }
}
