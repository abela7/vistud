<?php

namespace App\Study;

use App\Platform\Access\Guard;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\NotFound;
use App\Platform\Ids;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * An assignment's plan (the owner's reviews, 2026-10-02 and 2026-10-03): one way to track any assignment, from a
 * short essay to a group project. A plan is made of parts (the sections or deliverables), steps (small things to
 * do, under a part, under another step up to three levels deep, or on their own), milestones (dates to reach) and
 * criteria (what it is marked on, to check oneself against). Parts and steps are todo, doing, stuck or done, and
 * can have dates, a priority, notes, labels and a person of the team; the progress is what is done. A group
 * assignment has a team: names only, no accounts, since a student's data stays their own. Any of it can be added,
 * edited, reordered and deleted, and a starter or an AI's reply (App\Study\PlanMaker) makes a first plan in a
 * moment. Ticking the first thing starts a to-do assignment. A study aid, not evidence: its changes are not
 * journal records. The student's own plan only.
 */
final class Plans
{
    public const MAX_ITEMS = 200;

    public const MAX_TITLE = 200;

    public const MAX_NOTES = 2000;

    public const MAX_LABELS = 5;

    public const MAX_LABEL = 24;

    /** A step in a part is on level 1, a step in that step on 2, and so on. */
    public const MAX_DEPTH = 3;

    public const MAX_MEMBERS = 20;

    public const MAX_NAME = 80;

    public const KINDS = ['part', 'step', 'criterion', 'milestone'];

    /** Of a part or a step. */
    public const STATES = ['todo', 'doing', 'stuck', 'done'];

    public const CRITERION_STATES = ['not_yet', 'partly', 'met'];

    public const MILESTONE_STATES = ['pending', 'achieved'];

    public const PRIORITIES = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'urgent' => 'Urgent'];

    public function __construct(private Activities $activities, private Folders $folders) {}

    public function get(Principal $by, string $activityId): PlanDetails
    {
        $scope = Guard::learner($by);
        $this->activity($scope, $activityId);

        return new PlanDetails($activityId, $this->items($scope, $activityId), $this->members($scope, $activityId));
    }

    /**
     * Each plan in a workspace (without its team), for the cards and the Overview; assignments with no plan are left out.
     *
     * @return array<string, PlanDetails> by assignment ID
     */
    public function plans(Principal $by, string $workspaceId): array
    {
        $scope = Guard::learner($by);
        Input::workspace($scope, $workspaceId);

        $byActivity = [];
        foreach (LearnerTables::query($scope, 'activity_items')->where('workspace_id', $workspaceId)->orderBy('position')->orderBy('id')->get() as $row) {
            $byActivity[$row->activity_id][] = self::item($row);
        }

        $plans = [];
        foreach ($byActivity as $activityId => $items) {
            $plans[$activityId] = new PlanDetails($activityId, $items);
        }

        return $plans;
    }

    /**
     * How far each plan in a workspace has got, for the cards and the Overview; assignments without anything to tick are left out.
     *
     * @return array<string, PlanProgress> by assignment ID
     */
    public function summaries(Principal $by, string $workspaceId): array
    {
        $summaries = [];
        foreach ($this->plans($by, $workspaceId) as $activityId => $plan) {
            $progress = $plan->progress();
            if ($progress->total > 0) {
                $summaries[$activityId] = $progress;
            }
        }

        return $summaries;
    }

    /**
     * How each of the given assignments stands, for the cards and the Overview, from one read of the workspace's
     * plans: how far it has got, and how the work is going. Left out are the ones with nothing to tick, and the
     * ones with nothing to judge (done, or no plan).
     *
     * @param  list<ActivityDetails>  $activities
     * @return array{progress: array<string, PlanProgress>, health: array<string, array{state: string, reasons: list<string>}>}
     */
    public function standing(Principal $by, string $workspaceId, array $activities): array
    {
        $plans = $this->plans($by, $workspaceId);
        $standing = ['progress' => [], 'health' => []];
        foreach ($activities as $activity) {
            $plan = $plans[$activity->id] ?? null;
            if ($plan === null) {
                continue;
            }
            $progress = $plan->progress();
            if ($progress->total > 0) {
                $standing['progress'][$activity->id] = $progress;
            }
            if (($health = $plan->health($activity)) !== null) {
                $standing['health'][$activity->id] = $health;
            }
        }

        return $standing;
    }

    // ---------- Adding ----------

    public function addPart(Principal $by, string $activityId, mixed $title, mixed $marks = null): PlanItem
    {
        return $this->add($by, $activityId, 'part', null, ['title' => $title, 'marks' => $marks]);
    }

    /** A step in a part or in another step (up to MAX_DEPTH deep), or on its own for no parent. */
    public function addStep(Principal $by, string $activityId, mixed $title, ?string $parentId = null): PlanItem
    {
        return $this->add($by, $activityId, 'step', $parentId, ['title' => $title]);
    }

    public function addCriterion(Principal $by, string $activityId, mixed $title, mixed $marks = null): PlanItem
    {
        return $this->add($by, $activityId, 'criterion', null, ['title' => $title, 'marks' => $marks]);
    }

    /** A date to reach; the date can be left for later. */
    public function addMilestone(Principal $by, string $activityId, mixed $title, mixed $dueOn = null): PlanItem
    {
        return $this->add($by, $activityId, 'milestone', null, ['title' => $title, 'due_on' => $dueOn]);
    }

    // ---------- Changing ----------

    /** Renames an item, and gives a part or a criterion its marks (empty for none). */
    public function edit(Principal $by, string $itemId, mixed $title, mixed $marks = null): PlanItem
    {
        return $this->update($by, $itemId, ['title' => $title, 'marks' => $marks]);
    }

    /**
     * Changes an item: `title`, and what its kind has of `marks` (a part or a criterion), `start_on`, `due_on`
     * (a part, a step; a milestone has only `due_on`), `priority`, `notes`, `labels` (a list, or words separated by
     * commas) and `member_id` (a person of the team). Only the fields given are changed; empty clears one.
     *
     * @param  array<string, mixed>  $fields
     */
    public function update(Principal $by, string $itemId, array $fields): PlanItem
    {
        $scope = Guard::learner($by);

        $before = DB::transaction(function () use ($scope, $itemId, $fields) {
            $row = $this->row($scope, $itemId, lock: true);
            $changes = $this->fields($scope, $row->activity_id, $row->kind, $fields + ['title' => $row->title], $row);
            LearnerTables::query($scope, 'activity_items')->where('id', $itemId)->update($changes + ['updated_at' => now()]);

            return $row;
        });
        $item = self::item($this->row($scope, $itemId));

        // A section's folder keeps its name.
        if ($item->title !== $before->title && $this->folderRow($scope, $item->folderId) !== null) {
            $this->folders->rename($by, (string) $item->folderId, mb_substr($item->title, 0, Folders::MAX_NAME));
        }

        return $item;
    }

    /**
     * A section's own folder, inside the assignment's folder, for the notes, files and folders added to it: made
     * the first time it is needed (and again if it was deleted). Only a section has one.
     */
    public function folder(Principal $by, string $itemId): FolderDetails
    {
        $scope = Guard::learner($by);
        $row = $this->row($scope, $itemId);
        if ($row->kind !== 'part') {
            throw new Conflict('not_a_section', 'Only a section has a folder.');
        }
        if (($folder = $this->folderRow($scope, $row->folder_id)) !== null) {
            return $this->folders->find($by, $folder->id);
        }
        $parent = $this->activities->folder($by, $row->activity_id);

        return DB::transaction(function () use ($scope, $by, $itemId, $parent) {
            $row = $this->row($scope, $itemId, lock: true);
            if (($folder = $this->folderRow($scope, $row->folder_id)) !== null) {
                return $this->folders->find($by, $folder->id);
            }
            $folder = $this->folders->create($by, 'folder', $parent->id, mb_substr($row->title, 0, Folders::MAX_NAME));
            LearnerTables::query($scope, 'activity_items')->where('id', $itemId)->update(['folder_id' => $folder->id, 'updated_at' => now()]);

            return $folder;
        });
    }

    /**
     * todo, doing, stuck or done for a part or a step; not_yet, partly or met for a criterion; pending or
     * achieved for a milestone. Starting or finishing the first thing of a to-do assignment starts it (in progress).
     */
    public function setState(Principal $by, string $itemId, string $state): void
    {
        $scope = Guard::learner($by);

        $start = DB::transaction(function () use ($scope, $itemId, $state) {
            $row = $this->row($scope, $itemId, lock: true);
            $allowed = match ($row->kind) {
                'criterion' => self::CRITERION_STATES,
                'milestone' => self::MILESTONE_STATES,
                default => self::STATES,
            };
            Input::refuse(in_array($state, $allowed, true) ? [] : ['state' => 'Unknown state.']);
            $finished = in_array($state, ['done', 'achieved'], true);
            $wasFinished = in_array($row->state, ['done', 'achieved'], true);
            LearnerTables::query($scope, 'activity_items')->where('id', $itemId)->update([
                'state' => $state,
                'done_at' => $finished ? ($wasFinished ? $row->done_at : now()) : null,
                'updated_at' => now(),
            ]);

            return in_array($row->kind, ['part', 'step'], true) && in_array($state, ['doing', 'stuck', 'done'], true) ? $row->activity_id : null;
        });

        if ($start !== null && $this->activities->find($by, $start)->status === 'todo') {
            $this->activities->setStatus($by, $start, 'doing');
        }
    }

    /** One place up or down among the items beside it (the parts, the steps of a part, the criteria, the milestones). */
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

    /** Deletes an item; a part or a step takes the steps under it with it. */
    /**
     * Deletes an item and everything under it. A section's folder goes with it when it is empty; when it holds
     * something, it stays where it is (in the assignment's folder), with what is in it, and its name is returned.
     */
    public function delete(Principal $by, string $itemId): ?string
    {
        $scope = Guard::learner($by);

        $folderId = DB::transaction(function () use ($scope, $itemId) {
            $row = $this->row($scope, $itemId, lock: true);
            $gone = [$itemId];
            for ($frontier = [$itemId]; $frontier !== [];) {
                $frontier = LearnerTables::query($scope, 'activity_items')->whereIn('parent_id', $frontier)->pluck('id')->all();
                array_push($gone, ...$frontier);
            }
            LearnerTables::query($scope, 'activity_items')->whereIn('id', $gone)->delete();

            return $row->folder_id;
        });

        return $this->dropFolder($by, $scope, $folderId);
    }

    /**
     * Takes everything out of an assignment's plan: its parts, steps, milestones and criteria. The team stays.
     * For a pre-made plan or an AI's reply that was not what the student wanted.
     *
     * @return int how many things went
     */
    public function clear(Principal $by, string $activityId): int
    {
        $scope = Guard::learner($by);

        [$removed, $folders] = DB::transaction(function () use ($scope, $activityId) {
            $this->activity($scope, $activityId, lock: true);
            $folders = LearnerTables::query($scope, 'activity_items')->where('activity_id', $activityId)->whereNotNull('folder_id')->pluck('folder_id')->all();

            return [LearnerTables::query($scope, 'activity_items')->where('activity_id', $activityId)->delete(), $folders];
        });
        foreach ($folders as $folderId) {
            $this->dropFolder($by, $scope, $folderId);
        }

        return $removed;
    }

    // ---------- Starters and an AI's reply ----------

    /**
     * Adds a starter's parts, steps, milestones and criteria after what the plan already has.
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
            'milestones' => array_map(fn (string $title) => ['title' => $title, 'due_on' => null], $starter['milestones'] ?? []),
        ]);
    }

    /**
     * Adds what an AI's reply held (App\Study\PlanMaker::read()), after what the plan already has.
     *
     * @param  array{parts: list<array{title: string, marks: ?int, steps: list<string>}>, steps: list<string>, criteria: list<array{title: string, marks: ?int}>, milestones?: list<array{title: string, due_on: ?string}>}  $read
     * @return int how many items were added
     */
    public function addAll(Principal $by, string $activityId, array $read): int
    {
        $scope = Guard::learner($by);
        $milestones = $read['milestones'] ?? [];
        $added = 0;

        DB::transaction(function () use ($scope, $activityId, $read, $milestones, &$added) {
            $activity = $this->activity($scope, $activityId, lock: true);
            $new = count($read['parts']) + count($read['steps']) + count($read['criteria']) + count($milestones)
                + array_sum(array_map(fn (array $part) => count($part['steps']), $read['parts']));
            if ($this->count($scope, $activityId) + $new > self::MAX_ITEMS) {
                throw new Conflict('too_many', 'A plan holds at most '.self::MAX_ITEMS.' things.');
            }
            $insert = function (string $kind, ?string $parent, string $title, array $more = []) use ($scope, $activity, $activityId, &$added) {
                $id = Ids::new();
                LearnerTables::insert($scope, 'activity_items', [
                    'id' => $id, 'workspace_id' => $activity->workspace_id, 'activity_id' => $activityId, 'kind' => $kind, 'parent_id' => $parent,
                    'title' => mb_substr($title, 0, self::MAX_TITLE), 'state' => self::firstState($kind),
                    'position' => $this->nextPosition($scope, $activityId, $kind, $parent), 'created_at' => now(), 'updated_at' => now(),
                ] + $more + ['weight' => null]);
                $added++;

                return $id;
            };
            foreach ($read['parts'] as $part) {
                $partId = $insert('part', null, $part['title'], ['weight' => $part['marks'] ?? null]);
                foreach ($part['steps'] as $step) {
                    $insert('step', $partId, $step);
                }
            }
            foreach ($read['steps'] as $step) {
                $insert('step', null, $step);
            }
            foreach ($read['criteria'] as $criterion) {
                $insert('criterion', null, $criterion['title'], ['weight' => $criterion['marks'] ?? null]);
            }
            foreach ($milestones as $milestone) {
                $insert('milestone', null, $milestone['title'], ['due_on' => self::date($milestone['due_on'] ?? null) ?: null]);
            }
        });

        return $added;
    }

    // ---------- The team ----------

    /** A person of an assignment's team: a name. `$me` marks the student's own name (only one is). */
    public function addMember(Principal $by, string $activityId, mixed $name, bool $me = false): PlanMember
    {
        $scope = Guard::learner($by);
        $name = self::validatedName($name);
        $id = Ids::new();

        DB::transaction(function () use ($scope, $activityId, $name, $me, $id) {
            $activity = $this->activity($scope, $activityId, lock: true);
            $count = LearnerTables::query($scope, 'activity_members')->where('activity_id', $activityId)->count();
            if ($count >= self::MAX_MEMBERS) {
                throw new Conflict('too_many', 'A team holds at most '.self::MAX_MEMBERS.' people.');
            }
            if ($me) {
                LearnerTables::query($scope, 'activity_members')->where('activity_id', $activityId)->update(['me' => false]);
            }
            LearnerTables::insert($scope, 'activity_members', [
                'id' => $id, 'workspace_id' => $activity->workspace_id, 'activity_id' => $activityId, 'name' => $name, 'me' => $me,
                'position' => (int) LearnerTables::query($scope, 'activity_members')->where('activity_id', $activityId)->max('position') + 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return $this->memberRow($scope, $id);
    }

    public function renameMember(Principal $by, string $memberId, mixed $name): PlanMember
    {
        $scope = Guard::learner($by);
        $name = self::validatedName($name);
        $this->memberRow($scope, $memberId);
        LearnerTables::query($scope, 'activity_members')->where('id', $memberId)->update(['name' => $name, 'updated_at' => now()]);

        return $this->memberRow($scope, $memberId);
    }

    /** Whether this name is the student's own; making one so makes the others not. */
    public function markMe(Principal $by, string $memberId, bool $me): void
    {
        $scope = Guard::learner($by);

        DB::transaction(function () use ($scope, $memberId, $me) {
            $row = LearnerTables::query($scope, 'activity_members')->where('id', $memberId)->lockForUpdate()->first() ?? throw new NotFound;
            if ($me) {
                LearnerTables::query($scope, 'activity_members')->where('activity_id', $row->activity_id)->update(['me' => false]);
            }
            LearnerTables::query($scope, 'activity_members')->where('id', $memberId)->update(['me' => $me, 'updated_at' => now()]);
        });
    }

    /** Takes a person off the team; what was theirs is nobody's again. */
    public function removeMember(Principal $by, string $memberId): void
    {
        $scope = Guard::learner($by);

        DB::transaction(function () use ($scope, $memberId) {
            $this->memberRow($scope, $memberId);
            LearnerTables::query($scope, 'activity_items')->where('member_id', $memberId)->update(['member_id' => null]);
            LearnerTables::query($scope, 'activity_members')->where('id', $memberId)->delete();
        });
    }

    // ---------- Inside ----------

    /** @param array<string, mixed> $input */
    private function add(Principal $by, string $activityId, string $kind, ?string $parentId, array $input): PlanItem
    {
        $scope = Guard::learner($by);
        $id = Ids::new();

        DB::transaction(function () use ($scope, $activityId, $kind, $parentId, $input, $id) {
            $activity = $this->activity($scope, $activityId, lock: true);
            $fields = $this->fields($scope, $activityId, $kind, $input);
            if ($parentId !== null) {
                $parent = LearnerTables::query($scope, 'activity_items')->where('id', $parentId)->where('activity_id', $activityId)->whereIn('kind', ['part', 'step'])->first() ?? throw new NotFound;
                if ($parent->kind === 'step' && $this->depth($scope, $parent) >= self::MAX_DEPTH) {
                    throw new Conflict('too_deep', 'Steps go at most '.self::MAX_DEPTH.' levels deep.');
                }
            }
            if ($this->count($scope, $activityId) >= self::MAX_ITEMS) {
                throw new Conflict('too_many', 'A plan holds at most '.self::MAX_ITEMS.' things.');
            }
            LearnerTables::insert($scope, 'activity_items', $fields + [
                'id' => $id, 'workspace_id' => $activity->workspace_id, 'activity_id' => $activityId, 'kind' => $kind, 'parent_id' => $parentId,
                'state' => self::firstState($kind), 'position' => $this->nextPosition($scope, $activityId, $kind, $parentId),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return self::item($this->row($scope, $id));
    }

    /** A step's level: a step in a part is 1, in a step 2... */
    private function depth(LearnerScope $scope, object $step): int
    {
        $depth = 1;
        for ($row = $step; $row->parent_id !== null && $depth < 10; $depth++) {
            $row = LearnerTables::query($scope, 'activity_items')->where('id', $row->parent_id)->first();
            if ($row === null || $row->kind !== 'step') {
                break;
            }
        }

        return $depth;
    }

    /**
     * What an item of this kind can hold, checked and ready to write. With $existing, only the fields in $input
     * (and always the title) are changed; without it, the columns that are not given are left out.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function fields(LearnerScope $scope, string $activityId, string $kind, array $input, ?object $existing = null): array
    {
        $has = fn (string $key) => array_key_exists($key, $input);
        $title = Input::text($input, 'title');
        $errors = [];
        $out = [];

        if ($title === null) {
            $errors['title'] = 'Write what it is.';
        } elseif (mb_strlen($title) > self::MAX_TITLE) {
            $errors['title'] = 'Keep it to '.self::MAX_TITLE.' characters.';
        } else {
            $out['title'] = $title;
        }

        if (in_array($kind, ['part', 'criterion'], true) && $has('marks')) {
            $marks = is_string($input['marks']) ? trim($input['marks']) : $input['marks'];
            $weight = $marks === null || $marks === '' ? null : (is_numeric($marks) && (int) $marks == $marks ? (int) $marks : false);
            if ($weight === false || (is_int($weight) && ($weight < 1 || $weight > 100))) {
                $errors['marks'] = 'Enter a number from 1 to 100, or leave it empty.';
            } else {
                $out['weight'] = $weight;
            }
        }

        $dates = ['part' => ['start_on', 'due_on'], 'step' => ['start_on', 'due_on'], 'milestone' => ['due_on']][$kind] ?? [];
        foreach ($dates as $key) {
            if ($has($key)) {
                $date = self::date($input[$key]);
                if ($date === false) {
                    $errors[$key] = 'Enter a date.';
                } else {
                    $out[$key] = $date;
                }
            }
        }
        $start = array_key_exists('start_on', $out) ? $out['start_on'] : ($existing->start_on ?? null);
        $due = array_key_exists('due_on', $out) ? $out['due_on'] : ($existing->due_on ?? null);
        if (! isset($errors['due_on']) && $start !== null && $due !== null && $due < $start) {
            $errors['due_on'] = 'The due date is before the start date.';
        }

        if (in_array($kind, ['part', 'step'], true) && $has('priority')) {
            $priority = $input['priority'];
            if ($priority === null || $priority === '') {
                $out['priority'] = null;
            } elseif (is_string($priority) && isset(self::PRIORITIES[$priority])) {
                $out['priority'] = $priority;
            } else {
                $errors['priority'] = 'Choose low, medium, high or urgent.';
            }
        }

        if (in_array($kind, ['part', 'step', 'milestone'], true) && $has('notes')) {
            $notes = is_string($input['notes']) ? trim($input['notes']) : '';
            if (mb_strlen($notes) > self::MAX_NOTES) {
                $errors['notes'] = 'Keep the notes to '.self::MAX_NOTES.' characters.';
            } else {
                $out['notes'] = $notes === '' ? null : $notes;
            }
        }

        if (in_array($kind, ['part', 'step'], true) && $has('labels')) {
            $labels = self::labels($input['labels']);
            if ($labels === false) {
                $errors['labels'] = 'Up to '.self::MAX_LABELS.' labels, each up to '.self::MAX_LABEL.' characters.';
            } else {
                $out['labels'] = $labels === [] ? null : json_encode($labels, JSON_UNESCAPED_UNICODE);
            }
        }

        if (in_array($kind, ['part', 'step'], true) && $has('member_id')) {
            $member = $input['member_id'];
            if ($member === null || $member === '') {
                $out['member_id'] = null;
            } elseif (is_string($member) && LearnerTables::query($scope, 'activity_members')->where('id', $member)->where('activity_id', $activityId)->exists()) {
                $out['member_id'] = $member;
            } else {
                $errors['member'] = 'Pick someone from the team.';
            }
        }

        Input::refuse($errors);

        return $out;
    }

    /** @return string|null|false a date as Y-m-d, null for none, false when it isn't one */
    private static function date(mixed $value): string|null|false
    {
        if ($value === null || $value === '') {
            return null;
        }
        $parsed = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

        return $parsed !== false && $parsed->format('Y-m-d') === $value ? $value : false;
    }

    /**
     * Words separated by commas, or a list of them: trimmed, without repeats, up to MAX_LABELS of up to MAX_LABEL
     * characters each. False when there are too many or one is too long.
     *
     * @return list<string>|false
     */
    private static function labels(mixed $value): array|false
    {
        $words = is_array($value) ? $value : (is_string($value) ? explode(',', $value) : []);
        $labels = [];
        foreach ($words as $word) {
            $word = is_string($word) ? trim((string) preg_replace('/\s+/u', ' ', $word)) : '';
            if ($word === '') {
                continue;
            }
            if (mb_strlen($word) > self::MAX_LABEL) {
                return false;
            }
            if (! in_array(mb_strtolower($word), array_map('mb_strtolower', $labels), true)) {
                $labels[] = $word;
            }
        }

        return count($labels) > self::MAX_LABELS ? false : $labels;
    }

    private static function validatedName(mixed $name): string
    {
        $text = Input::text(['name' => $name], 'name');
        Input::refuse(array_filter([
            'name' => match (true) {
                $text === null => 'Write a name.',
                mb_strlen($text) > self::MAX_NAME => 'Keep the name to '.self::MAX_NAME.' characters.',
                default => null,
            },
        ]));

        return $text;
    }

    private static function firstState(string $kind): string
    {
        return match ($kind) {
            'criterion' => 'not_yet',
            'milestone' => 'pending',
            default => 'todo',
        };
    }

    /** @return list<PlanItem> */
    private function items(LearnerScope $scope, string $activityId): array
    {
        return LearnerTables::query($scope, 'activity_items')->where('activity_id', $activityId)->orderBy('position')->orderBy('id')->get()
            ->map(self::item(...))->all();
    }

    /** @return list<PlanMember> */
    private function members(LearnerScope $scope, string $activityId): array
    {
        return LearnerTables::query($scope, 'activity_members')->where('activity_id', $activityId)->orderBy('position')->orderBy('id')->get()
            ->map(self::member(...))->all();
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

    private function memberRow(LearnerScope $scope, string $id): PlanMember
    {
        return self::member(LearnerTables::query($scope, 'activity_members')->where('id', $id)->first() ?? throw new NotFound);
    }

    /** A section's folder, when it has one that still exists. */
    private function folderRow(LearnerScope $scope, ?string $folderId): ?object
    {
        return $folderId === null ? null : LearnerTables::query($scope, 'folders')->where('id', $folderId)->first();
    }

    /** Removes a section's folder when it is empty; returns its name when it stays, holding something. */
    private function dropFolder(Principal $by, LearnerScope $scope, ?string $folderId): ?string
    {
        $folder = $this->folderRow($scope, $folderId);
        if ($folder === null) {
            return null;
        }
        try {
            $this->folders->delete($by, $folder->id);

            return null;
        } catch (Conflict) {
            return $folder->name;
        }
    }

    private static function item(object $row): PlanItem
    {
        $labels = $row->labels === null ? [] : (json_decode((string) $row->labels, true) ?: []);

        return new PlanItem(
            $row->id, $row->kind, $row->parent_id, $row->title, $row->weight === null ? null : (int) $row->weight, $row->state, (int) $row->position,
            startOn: $row->start_on === null ? null : substr((string) $row->start_on, 0, 10),
            dueOn: $row->due_on === null ? null : substr((string) $row->due_on, 0, 10),
            priority: $row->priority,
            notes: $row->notes,
            labels: array_values(array_filter($labels, 'is_string')),
            memberId: $row->member_id,
            doneAt: $row->done_at === null ? null : (string) $row->done_at,
            folderId: $row->folder_id ?? null,
        );
    }

    private static function member(object $row): PlanMember
    {
        return new PlanMember($row->id, $row->name, (bool) $row->me, (int) $row->position);
    }
}
