<?php

namespace App\Study;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * An assignment's whole plan: its parts with their steps (which can hold steps, three levels deep), the steps
 * outside any part, its milestones, its marking criteria and its team. A part or a step that holds steps has no
 * state of its own: it is as far as they are. What gets ticked is the "units": the steps with no steps under them,
 * and a part with no steps.
 */
final readonly class PlanDetails
{
    /**
     * @param  list<PlanItem>  $items  in the order they are shown
     * @param  list<PlanMember>  $members
     */
    public function __construct(public string $activityId, public array $items, public array $members = []) {}

    /** @return list<PlanItem> */
    public function parts(): array
    {
        return $this->ofKind('part');
    }

    /** @return list<PlanItem> the steps of a part or of a step, or the ones outside every part for null */
    public function steps(?string $parentId = null): array
    {
        return array_values(array_filter($this->items, fn (PlanItem $item) => $item->kind === 'step' && $item->parentId === $parentId));
    }

    /** @return list<PlanItem> */
    public function criteria(): array
    {
        return $this->ofKind('criterion');
    }

    /** @return list<PlanItem> by date, the ones without a date last */
    public function milestones(): array
    {
        $milestones = $this->ofKind('milestone');
        usort($milestones, fn (PlanItem $a, PlanItem $b) => [$a->dueOn === null, $a->dueOn, $a->position] <=> [$b->dueOn === null, $b->dueOn, $b->position]);

        return $milestones;
    }

    public function empty(): bool
    {
        return $this->items === [];
    }

    public function item(string $id): ?PlanItem
    {
        foreach ($this->items as $item) {
            if ($item->id === $id) {
                return $item;
            }
        }

        return null;
    }

    public function member(?string $id): ?PlanMember
    {
        foreach ($this->members as $member) {
            if ($member->id === $id) {
                return $member;
            }
        }

        return null;
    }

    public function hasSteps(PlanItem $item): bool
    {
        return $this->steps($item->id) !== [];
    }

    /** @return list<PlanItem> what gets ticked under an item: its steps with no steps under them, or the item itself when it has none */
    public function units(PlanItem $item): array
    {
        $children = $this->steps($item->id);
        if ($children === []) {
            return [$item];
        }
        $units = [];
        foreach ($children as $child) {
            array_push($units, ...$this->units($child));
        }

        return $units;
    }

    /** The state to show: its own, or for a part or a step that holds steps, as far as they are (done, stuck, doing or todo). */
    public function stateOf(PlanItem $item): string
    {
        if (! in_array($item->kind, ['part', 'step'], true) || ! $this->hasSteps($item)) {
            return $item->state;
        }
        $states = array_map(fn (PlanItem $unit) => $unit->state, $this->units($item));
        $done = count(array_filter($states, fn (string $state) => $state === 'done'));

        return match (true) {
            $done === count($states) => 'done',
            in_array('stuck', $states, true) => 'stuck',
            $done > 0 || in_array('doing', $states, true) => 'doing',
            default => 'todo',
        };
    }

    /** @return array{0: int, 1: int} the units done and all of them under an item: [2, 3] */
    public function counts(PlanItem $item): array
    {
        $units = $this->units($item);

        return [count(array_filter($units, fn (PlanItem $unit) => $unit->state === 'done')), count($units)];
    }

    /** Some section has a weight: progress then counts each section for what it is worth, out of 100. */
    public function weighted(): bool
    {
        foreach ($this->parts() as $part) {
            if ($part->weight !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The weights, as the student set them: how much of 100 the sections (parts) were given, what is left or over,
     * and what each counts for. A section without a weight shares what is left equally with the others without one;
     * the steps outside every section count as one such section. Keyed by part ID, '' for those steps.
     *
     * @return array{given: int, left: int, over: int, unweighted: int, shares: array<string, float>}
     */
    public function weights(): array
    {
        $groups = $this->groups();
        $given = array_sum(array_map(fn (array $group) => $group['weight'] ?? 0, $groups));
        $unweighted = count(array_filter($groups, fn (array $group) => $group['weight'] === null));
        $left = max(0, 100 - $given);
        $shares = [];
        foreach ($groups as $key => $group) {
            $shares[$key] = (float) ($group['weight'] ?? ($unweighted > 0 ? $left / $unweighted : 0));
        }

        return ['given' => $given, 'left' => $left, 'over' => max(0, $given - 100), 'unweighted' => $unweighted, 'shares' => $shares];
    }

    /**
     * How far one section has got, and what that is worth: its share of 100 and the part of it earned so far.
     *
     * @return array{done: int, total: int, percent: int, share: float, earned: float}
     */
    public function standing(PlanItem $part): array
    {
        [$done, $total] = $this->counts($part);
        $share = $this->weighted() ? ($this->weights()['shares'][$part->id] ?? 0.0) : 0.0;

        return ['done' => $done, 'total' => $total, 'percent' => (int) round($done / max(1, $total) * 100), 'share' => $share, 'earned' => $share * $done / max(1, $total)];
    }

    public function progress(): PlanProgress
    {
        $groups = $this->groups();
        $done = array_sum(array_column($groups, 'done'));
        $total = array_sum(array_column($groups, 'total'));
        if ($total === 0) {
            return new PlanProgress(0, 0, 0);
        }
        if (! $this->weighted()) {
            return new PlanProgress($done, $total, (int) round($done / $total * 100));
        }
        // Out of 100, or of what was given when it is more: marks given to no section can't be earned.
        $weights = $this->weights();
        $earned = 0.0;
        foreach ($groups as $key => $group) {
            $earned += $weights['shares'][$key] * $group['done'] / $group['total'];
        }

        return new PlanProgress($done, $total, (int) min(100, round($earned / max(100, $weights['given']) * 100)), weighted: true);
    }

    /** @return array<string, array{weight: ?int, done: int, total: int}> each section, and '' for the steps outside them */
    private function groups(): array
    {
        $groups = [];
        foreach ($this->parts() as $part) {
            [$done, $total] = $this->counts($part);
            $groups[$part->id] = ['weight' => $part->weight, 'done' => $done, 'total' => $total];
        }
        $looseDone = $looseTotal = 0;
        foreach ($this->steps() as $step) {
            [$done, $total] = $this->counts($step);
            [$looseDone, $looseTotal] = [$looseDone + $done, $looseTotal + $total];
        }
        if ($looseTotal > 0) {
            $groups[''] = ['weight' => null, 'done' => $looseDone, 'total' => $looseTotal];
        }

        return $groups;
    }

    /**
     * How the criteria are met, by the student's own check: not yet counts 0, partly a half, met 1, each by its
     * marks when all have some, equally otherwise. $met is how many are met.
     *
     * @return array{met: int, total: int, percent: int}
     */
    public function criteriaScore(): array
    {
        $criteria = $this->criteria();
        $total = count($criteria);
        if ($total === 0) {
            return ['met' => 0, 'total' => 0, 'percent' => 0];
        }
        $weighted = array_reduce($criteria, fn (bool $all, PlanItem $c) => $all && $c->weight !== null, true);
        $worth = fn (PlanItem $c) => $weighted ? (int) $c->weight : 1;
        $credit = fn (PlanItem $c) => ['met' => 1.0, 'partly' => 0.5][$c->state] ?? 0.0;
        $sum = array_sum(array_map($worth, $criteria));
        $earned = array_sum(array_map(fn (PlanItem $c) => $worth($c) * $credit($c), $criteria));

        return [
            'met' => count(array_filter($criteria, fn (PlanItem $c) => $c->state === 'met')),
            'total' => $total,
            'percent' => (int) round($earned / $sum * 100),
        ];
    }

    /** The person a part, a step or a unit is for: its own, or the nearest part or step above it that has one. */
    public function memberOf(PlanItem $item): ?PlanMember
    {
        for ($depth = 0; $item !== null && $depth < 6; $depth++) {
            if ($item->memberId !== null) {
                return $this->member($item->memberId);
            }
            $item = $item->parentId === null ? null : $this->item($item->parentId);
        }

        return null;
    }

    /**
     * How the work is shared: for each person of the team, how many of the things to tick are theirs and how many
     * are done, then the ones nobody has. A person with nothing is left out.
     *
     * @return list<array{member: PlanMember|null, done: int, total: int}>
     */
    public function workload(): array
    {
        $byMember = [];
        foreach ($this->unitsOfAll() as $unit) {
            $key = $this->memberOf($unit)?->id ?? '';
            $byMember[$key] ??= ['done' => 0, 'total' => 0];
            $byMember[$key]['total']++;
            $byMember[$key]['done'] += $unit->state === 'done' ? 1 : 0;
        }
        $rows = [];
        foreach ($this->members as $member) {
            if (isset($byMember[$member->id])) {
                $rows[] = ['member' => $member] + $byMember[$member->id];
            }
        }
        if (isset($byMember[''])) {
            $rows[] = ['member' => null] + $byMember[''];
        }

        return $rows;
    }

    /** Past its day and not done: a part, a step or a milestone with a date. */
    public function overdue(PlanItem $item, string $today): bool
    {
        return $item->dueOn !== null && $item->dueOn < $today && $this->stateOf($item) !== 'done' && $item->state !== 'achieved' && in_array($item->kind, ['part', 'step', 'milestone'], true);
    }

    /** @return list<PlanItem> */
    public function overdueItems(string $today): array
    {
        return array_values(array_filter($this->items, fn (PlanItem $item) => $this->overdue($item, $today)));
    }

    /**
     * How the work is going, worked out and never typed: off track when it is past the deadline, or three things
     * are overdue or missed; at risk with a tight pace, stuck steps, overdue steps or a missed milestone; on track
     * otherwise. With the reasons, in words. Null when there is nothing to judge: no plan, or it is done.
     *
     * @return array{state: string, reasons: list<string>}|null
     */
    public function health(ActivityDetails $activity, ?CarbonInterface $now = null): ?array
    {
        $progress = $this->progress();
        if ($activity->status === 'done' || ($progress->total === 0 && $this->milestones() === [])) {
            return null;
        }
        $today = CarbonImmutable::instance($now ?? now())->setTimezone($activity->zone)->toDateString();
        $reasons = [];
        $pace = $progress->pace($activity, $now);
        if ($pace !== null && $pace['tone'] === 'late') {
            $reasons[] = 'The deadline has passed with '.$progress->left().' left';
        } elseif ($pace !== null && $pace['tone'] === 'tight') {
            $reasons[] = 'A tight pace: '.$pace['words'];
        }
        $stuck = count(array_filter($this->unitsOfAll(), fn (PlanItem $unit) => $unit->state === 'stuck'));
        if ($stuck > 0) {
            $reasons[] = $stuck === 1 ? '1 step is stuck' : "{$stuck} steps are stuck";
        }
        $overdue = count(array_filter($this->overdueItems($today), fn (PlanItem $item) => $item->kind !== 'milestone'));
        if ($overdue > 0) {
            $reasons[] = $overdue === 1 ? '1 thing is overdue' : "{$overdue} things are overdue";
        }
        $missed = count(array_filter($this->overdueItems($today), fn (PlanItem $item) => $item->kind === 'milestone'));
        if ($missed > 0) {
            $reasons[] = $missed === 1 ? 'A milestone was missed' : "{$missed} milestones were missed";
        }

        $state = match (true) {
            ($pace['tone'] ?? null) === 'late' || $overdue + $missed >= 3 => 'off_track',
            $reasons !== [] => 'at_risk',
            default => 'on_track',
        };

        return ['state' => $state, 'reasons' => $reasons];
    }

    /** @return list<PlanItem> every thing to tick in the plan */
    private function unitsOfAll(): array
    {
        $units = [];
        foreach ($this->items as $item) {
            if ($item->parentId === null && in_array($item->kind, ['part', 'step'], true)) {
                array_push($units, ...$this->units($item));
            }
        }

        return $units;
    }

    /** @return list<PlanItem> */
    private function ofKind(string $kind): array
    {
        return array_values(array_filter($this->items, fn (PlanItem $item) => $item->kind === $kind));
    }
}
