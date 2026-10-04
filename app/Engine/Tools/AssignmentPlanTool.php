<?php

namespace App\Engine\Tools;

use App\Platform\Access\Principal;
use App\Study\Activities;
use App\Study\PlanDetails;
use App\Study\PlanItem;
use App\Study\Plans;

/** One assignment's plan: its sections with their weights, the tasks under each (and their sub-tasks), the marking criteria and the milestones. */
final class AssignmentPlanTool implements Tool
{
    public function __construct(private Activities $activities, private Plans $plans) {}

    public function name(): string
    {
        return 'assignment_plan';
    }

    public function description(): string
    {
        return 'The plan of one assignment: its sections (with their weight out of 100 and how far each is), the tasks and sub-tasks under each with their state and dates, the marking criteria and whether each is met, and the milestones.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => ['assignment' => ['type' => 'string', 'description' => 'The assignment\'s title (or part of it).']], 'required' => ['assignment'], 'additionalProperties' => false];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        $activity = Lookup::one($this->activities->list($by, $context->workspaceId), Lookup::text($input, 'assignment') ?? '', fn ($a) => $a->title, 'assignment');
        if (is_string($activity)) {
            return $activity;
        }
        $plan = $this->plans->get($by, $activity->id);
        if ($plan->empty()) {
            return "\"{$activity->title}\" has no plan yet. The student can add sections and tasks, use a pre-made plan, or ask you for one.";
        }
        $progress = $plan->progress();
        $out = ['assignment' => $activity->title, 'due' => $activity->dueOn, 'progress' => "{$progress->done} of {$progress->total} done, {$progress->percent}%", 'sections' => [], 'tasks_outside_sections' => [], 'criteria' => [], 'milestones' => []];
        foreach ($plan->parts() as $part) {
            $out['sections'][] = array_filter([
                'section' => $part->title,
                'weight' => $part->weight !== null ? "{$part->weight}%" : null,
                'state' => $this->state($plan, $part),
                'due' => $part->dueOn,
                'tasks' => $this->steps($plan, $part->id),
            ], fn ($v) => $v !== null && $v !== []);
        }
        $out['tasks_outside_sections'] = $this->steps($plan, null);
        foreach ($plan->criteria() as $criterion) {
            $out['criteria'][] = array_filter(['criterion' => $criterion->title, 'marks' => $criterion->weight, 'state' => self::words($criterion->state)]);
        }
        foreach ($plan->milestones() as $milestone) {
            $out['milestones'][] = array_filter(['milestone' => $milestone->title, 'on' => $milestone->dueOn, 'state' => $milestone->state]);
        }

        return Lookup::json(array_filter($out, fn ($v) => $v !== [] && $v !== null));
    }

    /** @return list<array<string, mixed>> */
    private function steps(PlanDetails $plan, ?string $parentId, int $depth = 0): array
    {
        $rows = [];
        foreach ($plan->steps($parentId) as $step) {
            $rows[] = array_filter([
                'task' => $step->title,
                'state' => $this->state($plan, $step),
                'due' => $step->dueOn,
                'priority' => $step->priority,
                'sub_tasks' => $depth < 2 ? $this->steps($plan, $step->id, $depth + 1) : [],
            ], fn ($v) => $v !== null && $v !== []);
        }

        return $rows;
    }

    private function state(PlanDetails $plan, PlanItem $item): string
    {
        return self::words($plan->stateOf($item));
    }

    /** The plan's states as the screens say them. */
    private static function words(string $state): string
    {
        return ['todo' => 'to do', 'doing' => 'in progress', 'not_yet' => 'not yet'][$state] ?? $state;
    }
}
