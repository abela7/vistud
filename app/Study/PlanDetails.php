<?php

namespace App\Study;

/** An assignment's whole plan: its parts and their steps, the steps outside any part, and its criteria. */
final readonly class PlanDetails
{
    /** @param list<PlanItem> $items in the order they are shown */
    public function __construct(public string $activityId, public array $items) {}

    /** @return list<PlanItem> */
    public function parts(): array
    {
        return $this->ofKind('part');
    }

    /** @return list<PlanItem> the steps of a part, or the ones outside every part for null */
    public function steps(?string $partId = null): array
    {
        return array_values(array_filter($this->items, fn (PlanItem $item) => $item->kind === 'step' && $item->parentId === $partId));
    }

    /** @return list<PlanItem> */
    public function criteria(): array
    {
        return $this->ofKind('criterion');
    }

    public function empty(): bool
    {
        return $this->items === [];
    }

    /** Every part has its marks: progress then counts a part for as much as it is worth. */
    public function weighted(): bool
    {
        $parts = $this->parts();

        return $parts !== [] && array_reduce($parts, fn (bool $all, PlanItem $part) => $all && $part->weight !== null, true);
    }

    public function progress(): PlanProgress
    {
        $groups = [];
        foreach ($this->parts() as $part) {
            $steps = $this->steps($part->id);
            $groups[] = [
                $part->weight ?? 0,
                $steps === [] ? (int) $part->done() : count(array_filter($steps, fn (PlanItem $step) => $step->done())),
                $steps === [] ? 1 : count($steps),
            ];
        }
        $loose = $this->steps();
        $looseDone = count(array_filter($loose, fn (PlanItem $step) => $step->done()));
        $done = array_sum(array_column($groups, 1)) + $looseDone;
        $total = array_sum(array_column($groups, 2)) + count($loose);
        if ($total === 0) {
            return new PlanProgress(0, 0, 0);
        }

        if (! $this->weighted()) {
            return new PlanProgress($done, $total, (int) round($done / $total * 100));
        }
        // By the marks: what the parts leave of 100 goes to the steps outside them.
        $weights = array_column($groups, 0);
        $looseWeight = $loose === [] ? 0 : max(0, 100 - array_sum($weights));
        $sum = array_sum($weights) + $looseWeight;
        $earned = 0.0;
        foreach ($groups as [$weight, $groupDone, $groupTotal]) {
            $earned += $weight * $groupDone / $groupTotal;
        }
        if ($loose !== []) {
            $earned += $looseWeight * $looseDone / count($loose);
        }

        return new PlanProgress($done, $total, (int) round($earned / $sum * 100), weighted: true);
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

    /** @return list<PlanItem> */
    private function ofKind(string $kind): array
    {
        return array_values(array_filter($this->items, fn (PlanItem $item) => $item->kind === $kind));
    }
}
