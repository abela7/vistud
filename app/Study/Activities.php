<?php

namespace App\Study;

use App\Brain\Journal\Vocabulary;
use App\Platform\Access\Guard;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\NotFound;
use App\Platform\Ids;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Assignments and tasks (docs/specs/study-memory.md §3): an assignment, a
 * quiz, an exam, a lab, a problem set or any other thing to do, with a due
 * date and where the student is with it (to do, doing, done). Each change is
 * also a new revision of the activity's `activity` record in the journal
 * (ADR 0002), so evidence can name it and the calendar can show it. The
 * student's own stream only.
 */
final class Activities
{
    public const MAX_TITLE = 200;

    public const STATUSES = ['todo', 'doing', 'done'];

    /** The kinds a student picks from, in plain words; lectures come with the calendar. */
    public const KINDS = [
        'assignment' => 'Assignment',
        'quiz' => 'Quiz',
        'exam' => 'Exam',
        'lab' => 'Lab',
        'problem_set' => 'Problem set',
        'other' => 'To-do',
    ];

    public function __construct(private Memory $memory) {}

    /** @return list<ActivityDetails> what's still open first, soonest due first; then what's done, latest first */
    public function list(Principal $by, string $workspaceId): array
    {
        $scope = Guard::learner($by);
        Input::workspace($scope, $workspaceId);
        $rows = LearnerTables::query($scope, 'activities')->where('workspace_id', $workspaceId)->get()->map(self::details(...))->all();

        usort($rows, fn (ActivityDetails $a, ActivityDetails $b) => ($a->status === 'done') <=> ($b->status === 'done')
            ?: ($a->status === 'done'
                ? $b->updatedAt <=> $a->updatedAt
                : [$a->dueOn === null, $a->dueOn, $a->createdAt] <=> [$b->dueOn === null, $b->dueOn, $b->createdAt]));

        return $rows;
    }

    public function find(Principal $by, string $id): ActivityDetails
    {
        return self::details($this->row(Guard::learner($by), $id));
    }

    /** @param array{kind?: mixed, title?: mixed, due_on?: mixed, module_id?: mixed} $input */
    public function create(Principal $by, string $workspaceId, array $input): ActivityDetails
    {
        $scope = Guard::learner($by);
        $fields = self::validated($input);
        $id = Ids::new();

        DB::transaction(function () use ($scope, $by, $workspaceId, $fields, $id) {
            Input::workspace($scope, $workspaceId, lock: true);
            $fields['module_id'] = $this->moduleIn($scope, $workspaceId, $fields['module_id']);
            LearnerTables::insert($scope, 'activities', $fields + [
                'id' => $id, 'workspace_id' => $workspaceId, 'status' => 'todo', 'revision' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->record($scope, $by, $this->row($scope, $id, lock: true));
        });

        return $this->find($by, $id);
    }

    /** @param array{kind?: mixed, title?: mixed, due_on?: mixed, module_id?: mixed} $input */
    public function update(Principal $by, string $id, array $input): ActivityDetails
    {
        $scope = Guard::learner($by);
        $fields = self::validated($input);

        DB::transaction(function () use ($scope, $by, $id, $fields) {
            $row = $this->row($scope, $id, lock: true);
            $fields['module_id'] = $this->moduleIn($scope, $row->workspace_id, $fields['module_id']);
            $this->change($scope, $by, $row, $fields);
        });

        return $this->find($by, $id);
    }

    /** To do, doing or done. */
    public function setStatus(Principal $by, string $id, string $status): void
    {
        $scope = Guard::learner($by);
        Input::refuse(in_array($status, self::STATUSES, true) ? [] : ['status' => 'Unknown status.']);

        DB::transaction(function () use ($scope, $by, $id, $status) {
            $row = $this->row($scope, $id, lock: true);
            if ($row->status !== $status) {
                $this->change($scope, $by, $row, ['status' => $status]);
            }
        });
    }

    public function delete(Principal $by, string $id): void
    {
        $scope = Guard::learner($by);

        DB::transaction(function () use ($scope, $by, $id) {
            $row = $this->row($scope, $id, lock: true);
            LearnerTables::query($scope, 'activities')->where('id', $id)->delete();
            $row->revision++;
            $this->record($scope, $by, $row, deleted: true);
        });
    }

    private function change(LearnerScope $scope, Principal $by, object $row, array $fields): void
    {
        LearnerTables::query($scope, 'activities')->where('id', $row->id)->update($fields + ['revision' => $row->revision + 1, 'updated_at' => now()]);
        $this->record($scope, $by, $this->row($scope, $row->id, lock: true));
    }

    /** A new revision of the activity's journal record, with its current details. */
    private function record(LearnerScope $scope, Principal $by, object $row, bool $deleted = false): void
    {
        $this->memory->append($scope, [[
            'id' => Ids::new(),
            'kind' => 'record',
            'actor' => Memory::actor($scope, $by),
            'occurred_at' => Memory::now(),
            'body' => array_filter([
                'record_type' => 'activity',
                'record_id' => $row->id,
                'revision' => (int) $row->revision,
                'workspace' => $row->workspace_id,
                'module' => $row->module_id,
                'kind' => $row->kind,
                'title' => $row->title,
                'due_on' => $row->due_on,
                'progress' => $row->status,
                'status' => $deleted ? 'deleted' : 'active',
            ], fn ($value) => $value !== null),
        ]], $row->workspace_id);
    }

    private function moduleIn(LearnerScope $scope, string $workspaceId, ?string $moduleId): ?string
    {
        if ($moduleId === null) {
            return null;
        }

        return (LearnerTables::query($scope, 'modules')->where('id', $moduleId)->where('workspace_id', $workspaceId)->first() ?? throw new NotFound)->id;
    }

    private function row(LearnerScope $scope, string $id, bool $lock = false): object
    {
        $query = LearnerTables::query($scope, 'activities')->where('id', $id);

        return ($lock ? $query->lockForUpdate() : $query)->first() ?? throw new NotFound;
    }

    /** @return array{kind: string, title: string, due_on: ?string, module_id: ?string} */
    private static function validated(array $input): array
    {
        $title = Input::text($input, 'title');
        $kind = $input['kind'] ?? 'assignment';
        $due = $input['due_on'] ?? null;
        $due = $due === '' ? null : $due;
        $parsed = is_string($due) ? DateTimeImmutable::createFromFormat('!Y-m-d', $due) : false;
        $module = $input['module_id'] ?? null;

        Input::refuse(array_filter([
            'title' => match (true) {
                $title === null => 'Say what it is.',
                mb_strlen($title) > self::MAX_TITLE => 'Keep it to '.self::MAX_TITLE.' characters.',
                default => null,
            },
            'kind' => in_array($kind, Vocabulary::ACTIVITY_KINDS, true) ? null : 'Choose what kind it is.',
            'due_on' => $due === null || ($parsed !== false && $parsed->format('Y-m-d') === $due) ? null : 'Enter a date.',
        ]));

        return ['kind' => $kind, 'title' => $title, 'due_on' => $due, 'module_id' => is_string($module) && $module !== '' ? $module : null];
    }

    private static function details(object $row): ActivityDetails
    {
        return new ActivityDetails(
            $row->id, $row->workspace_id, $row->module_id, $row->kind, $row->title, $row->due_on, $row->status,
            (string) $row->created_at, (string) $row->updated_at,
        );
    }
}
