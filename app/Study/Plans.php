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
 * An assignment's plan (the owner's review, 2026-10-02): one way to track any assignment, whatever the course. A
 * plan is made of parts (the sections or deliverables), steps (small things to do, under a part or on their
 * own) and criteria (what it is marked on, to check oneself against). Parts and steps are ticked off, which is
 * the progress; criteria are checked as not yet, partly or met, which is how it should score. Any of them can be
 * added, renamed, given marks, reordered and deleted, and a starter or an AI's reply (App\Study\PlanMaker) makes
 * a first plan in a moment. Ticking the first thing starts a to-do assignment. A study aid, not evidence: its
 * changes are not journal records. The student's own plan only.
 */
final class Plans
{
    public const MAX_ITEMS = 200;

    public const MAX_TITLE = 200;

    public const KINDS = ['part', 'step', 'criterion'];

    public const CRITERION_STATES = ['not_yet', 'partly', 'met'];

    public function __construct(private Activities $activities) {}

    public function get(Principal $by, string $activityId): PlanDetails
    {
        $scope = Guard::learner($by);
        $this->activity($scope, $activityId);

        return new PlanDetails($activityId, $this->items($scope, $activityId));
    }

    /**
     * How far each plan in a workspace has got, for the cards and the Overview; assignments without a plan are left out.
     *
     * @return array<string, PlanProgress> by assignment ID
     */
    public function summaries(Principal $by, string $workspaceId): array
    {
        $scope = Guard::learner($by);
        Input::workspace($scope, $workspaceId);

        $byActivity = [];
        foreach (LearnerTables::query($scope, 'activity_items')->where('workspace_id', $workspaceId)->orderBy('position')->orderBy('id')->get() as $row) {
            $byActivity[$row->activity_id][] = self::item($row);
        }
        $summaries = [];
        foreach ($byActivity as $activityId => $items) {
            $progress = (new PlanDetails($activityId, $items))->progress();
            if ($progress->total > 0) {
                $summaries[$activityId] = $progress;
            }
        }

        return $summaries;
    }

    public function addPart(Principal $by, string $activityId, mixed $title, mixed $marks = null): PlanItem
    {
        return $this->add($by, $activityId, 'part', $title, $marks, null);
    }

    /** A step in a part, or on its own for no part. */
    public function addStep(Principal $by, string $activityId, mixed $title, ?string $partId = null): PlanItem
    {
        return $this->add($by, $activityId, 'step', $title, null, $partId);
    }

    public function addCriterion(Principal $by, string $activityId, mixed $title, mixed $marks = null): PlanItem
    {
        return $this->add($by, $activityId, 'criterion', $title, $marks, null);
    }

    /** Renames an item, and gives a part or a criterion its marks (empty for none). */
    public function edit(Principal $by, string $itemId, mixed $title, mixed $marks = null): PlanItem
    {
        $scope = Guard::learner($by);
        $fields = self::validated($title, $marks);

        DB::transaction(function () use ($scope, $itemId, $fields) {
            $row = $this->row($scope, $itemId, lock: true);
            LearnerTables::query($scope, 'activity_items')->where('id', $itemId)->update([
                'title' => $fields['title'],
                'weight' => $row->kind === 'step' ? null : $fields['weight'],
                'updated_at' => now(),
            ]);
        });

        return self::item($this->row($scope, $itemId));
    }

    /**
     * todo or done for a part or a step, not_yet, partly or met for a criterion. Ticking the first thing of a
     * to-do assignment starts it (in progress).
     */
    public function setState(Principal $by, string $itemId, string $state): void
    {
        $scope = Guard::learner($by);

        $start = DB::transaction(function () use ($scope, $itemId, $state) {
            $row = $this->row($scope, $itemId, lock: true);
            $allowed = $row->kind === 'criterion' ? self::CRITERION_STATES : ['todo', 'done'];
            Input::refuse(in_array($state, $allowed, true) ? [] : ['state' => 'Unknown state.']);
            LearnerTables::query($scope, 'activity_items')->where('id', $itemId)->update(['state' => $state, 'updated_at' => now()]);

            return $row->kind !== 'criterion' && $state === 'done' ? $row->activity_id : null;
        });

        if ($start !== null && $this->activities->find($by, $start)->status === 'todo') {
            $this->activities->setStatus($by, $start, 'doing');
        }
    }

    /** One place up or down among the items beside it (the parts, the steps of a part, the criteria). */
    public function move(Principal $by, string $itemId, string $direction): void
    {
        $scope = Guard::learner($by);
        Input::refuse(in_array($direction, ['up', 'down'], true) ? [] : ['direction' => 'Up or down.']);

        DB::transaction(function () use ($scope, $itemId, $direction) {
            $row = $this->row($scope, $itemId, lock: true);
            $order = LearnerTables::query($scope, 'activity_items')->where('activity_id', $row->activity_id)->where('kind', $row->kind)
                ->when($row->parent_id === null, fn ($query) => $query->whereNull('parent_id'), fn ($query) => $query->where('parent_id', $row->parent_id))
                ->orderBy('position')->orderBy('id')->pluck('id')->all();
            $at = array_search($itemId, $order, true);
            $to = $direction === 'up' ? $at - 1 : $at + 1;
            if ($to < 0 || $to >= count($order)) {
                return;
            }
            [$order[$at], $order[$to]] = [$order[$to], $order[$at]];
            foreach ($order as $position => $id) {
                LearnerTables::query($scope, 'activity_items')->where('id', $id)->update(['position' => $position + 1]);
            }
        });
    }

    /** Deletes an item; a part takes its steps with it. */
    public function delete(Principal $by, string $itemId): void
    {
        $scope = Guard::learner($by);

        DB::transaction(function () use ($scope, $itemId) {
            $row = $this->row($scope, $itemId, lock: true);
            if ($row->kind === 'part') {
                LearnerTables::query($scope, 'activity_items')->where('parent_id', $itemId)->delete();
            }
            LearnerTables::query($scope, 'activity_items')->where('id', $itemId)->delete();
        });
    }

    /**
     * Adds a starter's parts, steps and criteria after what the plan already has.
     *
     * @return int how many items were added
     */
    public function applyStarter(Principal $by, string $activityId, string $key): int
    {
        $starter = PlanStarters::get($key) ?? throw new NotFound;

        return $this->addAll($by, $activityId, [
            'parts' => array_map(fn (array $part) => ['title' => $part[0], 'marks' => null, 'steps' => $part[1]], $starter['parts']),
            'steps' => $starter['steps'],
            'criteria' => array_map(fn (string $title) => ['title' => $title, 'marks' => null], $starter['criteria']),
        ]);
    }

    /**
     * Adds what an AI's reply held (App\Study\PlanMaker::read()), after what the plan already has.
     *
     * @param  array{parts: list<array{title: string, marks: ?int, steps: list<string>}>, steps: list<string>, criteria: list<array{title: string, marks: ?int}>}  $read
     * @return int how many items were added
     */
    public function addAll(Principal $by, string $activityId, array $read): int
    {
        $scope = Guard::learner($by);
        $added = 0;

        DB::transaction(function () use ($scope, $activityId, $read, &$added) {
            $activity = $this->activity($scope, $activityId, lock: true);
            $count = $this->count($scope, $activityId);
            $new = count($read['parts']) + count($read['steps']) + count($read['criteria']) + array_sum(array_map(fn (array $part) => count($part['steps']), $read['parts']));
            if ($count + $new > self::MAX_ITEMS) {
                throw new Conflict('too_many', 'A plan holds at most '.self::MAX_ITEMS.' things.');
            }
            $next = fn (string $kind, ?string $parent = null) => $this->nextPosition($scope, $activityId, $kind, $parent);
            $insert = function (string $kind, ?string $parent, string $title, ?int $weight) use ($scope, $activity, $activityId, &$added, $next) {
                $id = Ids::new();
                LearnerTables::insert($scope, 'activity_items', [
                    'id' => $id, 'workspace_id' => $activity->workspace_id, 'activity_id' => $activityId, 'kind' => $kind, 'parent_id' => $parent,
                    'title' => mb_substr($title, 0, self::MAX_TITLE), 'weight' => $weight, 'state' => $kind === 'criterion' ? 'not_yet' : 'todo',
                    'position' => $next($kind, $parent), 'created_at' => now(), 'updated_at' => now(),
                ]);
                $added++;

                return $id;
            };
            foreach ($read['parts'] as $part) {
                $partId = $insert('part', null, $part['title'], $part['marks'] ?? null);
                foreach ($part['steps'] as $step) {
                    $insert('step', $partId, $step, null);
                }
            }
            foreach ($read['steps'] as $step) {
                $insert('step', null, $step, null);
            }
            foreach ($read['criteria'] as $criterion) {
                $insert('criterion', null, $criterion['title'], $criterion['marks'] ?? null);
            }
        });

        return $added;
    }

    private function add(Principal $by, string $activityId, string $kind, mixed $title, mixed $marks, ?string $parentId): PlanItem
    {
        $scope = Guard::learner($by);
        $fields = self::validated($title, $kind === 'step' ? null : $marks);
        $id = Ids::new();

        DB::transaction(function () use ($scope, $activityId, $kind, $fields, $parentId, $id) {
            $activity = $this->activity($scope, $activityId, lock: true);
            if ($parentId !== null) {
                LearnerTables::query($scope, 'activity_items')->where('id', $parentId)->where('activity_id', $activityId)->where('kind', 'part')->exists() || throw new NotFound;
            }
            if ($this->count($scope, $activityId) >= self::MAX_ITEMS) {
                throw new Conflict('too_many', 'A plan holds at most '.self::MAX_ITEMS.' things.');
            }
            LearnerTables::insert($scope, 'activity_items', [
                'id' => $id, 'workspace_id' => $activity->workspace_id, 'activity_id' => $activityId, 'kind' => $kind, 'parent_id' => $parentId,
                'title' => $fields['title'], 'weight' => $fields['weight'], 'state' => $kind === 'criterion' ? 'not_yet' : 'todo',
                'position' => $this->nextPosition($scope, $activityId, $kind, $parentId), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return self::item($this->row($scope, $id));
    }

    /** @return list<PlanItem> */
    private function items(LearnerScope $scope, string $activityId): array
    {
        return LearnerTables::query($scope, 'activity_items')->where('activity_id', $activityId)->orderBy('position')->orderBy('id')->get()
            ->map(self::item(...))->all();
    }

    private function count(LearnerScope $scope, string $activityId): int
    {
        return LearnerTables::query($scope, 'activity_items')->where('activity_id', $activityId)->count();
    }

    private function nextPosition(LearnerScope $scope, string $activityId, string $kind, ?string $parentId): int
    {
        return (int) LearnerTables::query($scope, 'activity_items')->where('activity_id', $activityId)->where('kind', $kind)
            ->when($parentId === null, fn ($query) => $query->whereNull('parent_id'), fn ($query) => $query->where('parent_id', $parentId))
            ->max('position') + 1;
    }

    private function activity(LearnerScope $scope, string $activityId, bool $lock = false): object
    {
        $query = LearnerTables::query($scope, 'activities')->where('id', $activityId);

        return ($lock ? $query->lockForUpdate() : $query)->first() ?? throw new NotFound;
    }

    private function row(LearnerScope $scope, string $id, bool $lock = false): object
    {
        $query = LearnerTables::query($scope, 'activity_items')->where('id', $id);

        return ($lock ? $query->lockForUpdate() : $query)->first() ?? throw new NotFound;
    }

    /** @return array{title: string, weight: ?int} */
    private static function validated(mixed $title, mixed $marks): array
    {
        $text = Input::text(['title' => $title], 'title');
        $marks = is_string($marks) ? trim($marks) : $marks;
        $weight = $marks === null || $marks === '' ? null : (is_numeric($marks) && (int) $marks == $marks ? (int) $marks : false);

        Input::refuse(array_filter([
            'title' => match (true) {
                $text === null => 'Write what it is.',
                mb_strlen($text) > self::MAX_TITLE => 'Keep it to '.self::MAX_TITLE.' characters.',
                default => null,
            },
            'marks' => $weight === false || (is_int($weight) && ($weight < 1 || $weight > 100)) ? 'Enter marks from 1 to 100, or leave it empty.' : null,
        ]));

        return ['title' => $text, 'weight' => $weight];
    }

    private static function item(object $row): PlanItem
    {
        return new PlanItem($row->id, $row->kind, $row->parent_id, $row->title, $row->weight === null ? null : (int) $row->weight, $row->state, (int) $row->position);
    }
}
