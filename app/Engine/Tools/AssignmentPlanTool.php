<?php

namespace App\Engine\Tools;

use App\Engine\Context\Stack;
use App\Platform\Access\Principal;
use App\Study\Activities;
use App\Study\ActivityDetails;
use App\Study\AssignmentLog;
use App\Study\AssignmentLogEntry;
use App\Study\Fence;
use App\Study\PlanDetails;
use App\Study\PlanItem;
use App\Study\Plans;
use Carbon\CarbonImmutable;

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
        return ['type' => 'object', 'properties' => [
            'assignment' => ['type' => 'string', 'description' => 'The assignment\'s title (or part of it).'],
            'log_page' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Instead of the plan: a page of the assignment\'s log, newest first, '.Stack::LOG_LINES.' a page. Page 1 is what your instructions show; 2, 3… go further back.'],
        ], 'required' => ['assignment'], 'additionalProperties' => false];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        $wanted = Lookup::text($input, 'assignment') ?? '';
        $activity = ($wanted === '' && $context->activityId !== null)
            ? $this->activities->find($by, $context->activityId)
            : Lookup::one($this->activities->list($by, $context->workspaceId), $wanted, fn ($a) => $a->title, 'assignment');
        if (is_string($activity)) {
            return $activity;
        }
        if (is_int($input['log_page'] ?? null) && $input['log_page'] >= 1) {
            return $this->logPage($by, $activity, $input['log_page']);
        }
        $plan = $this->plans->get($by, $activity->id);
        if ($plan->empty()) {
            return "\"{$activity->title}\" has no plan yet. The student can add sections and tasks, use a pre-made plan, or ask you for one.";
        }
        $out = self::format($plan, $activity);

        return Lookup::json($out);
    }

    /**
     * A page of the assignment's log, newest first (Stack::LOG_LINES a page): the instructions carry page 1, and a
     * paper worked on for months keeps up to AssignmentLog::MAX_ENTRIES, so a later model can still read why
     * something was decided in the first month. The entries are the student's and the tutor's words: fenced.
     */
    private function logPage(Principal $by, ActivityDetails $activity, int $page): string
    {
        $entries = app(AssignmentLog::class)->list($by, $activity->id, limit: Stack::LOG_LINES, offset: ($page - 1) * Stack::LOG_LINES);
        if ($entries === []) {
            return "Page {$page} of \"{$activity->title}\"'s log is empty: there are no older entries.";
        }
        $lines = array_map(fn (AssignmentLogEntry $e) => '- ['.CarbonImmutable::parse($e->createdAt)->format('Y-m-d H:i')."] [{$e->stage}] {$e->kind}: {$e->text} (by {$e->by}, id {$e->id})", $entries);

        return "Log page {$page}, newest first:\n".Fence::wrap(implode("\n", $lines));
    }

    /** @return array<string, mixed> */
    public static function format(PlanDetails $plan, ActivityDetails $activity): array
    {
        $progress = $plan->progress();
        $out = ['id' => $activity->id, 'assignment' => $activity->title, 'due' => $activity->dueOn, 'progress' => $progress->words(), 'sections' => [], 'tasks_outside_sections' => [], 'criteria' => [], 'milestones' => []];
        foreach ($plan->parts() as $part) {
            $out['sections'][] = array_filter([
                'id' => $part->id,
                'section' => $part->title,
                'weight' => $part->weight !== null ? "{$part->weight}%" : null,
                'state' => self::state($plan, $part),
                'due' => $part->dueOn,
                'tasks' => self::steps($plan, $part->id),
            ], fn ($v) => $v !== null && $v !== []);
        }
        $out['tasks_outside_sections'] = self::steps($plan, null);
        foreach ($plan->criteria() as $criterion) {
            $out['criteria'][] = array_filter(['id' => $criterion->id, 'criterion' => $criterion->title, 'marks' => $criterion->weight, 'state' => self::words($criterion->state)]);
        }
        foreach ($plan->milestones() as $milestone) {
            $out['milestones'][] = array_filter(['id' => $milestone->id, 'milestone' => $milestone->title, 'on' => $milestone->dueOn, 'state' => $milestone->state]);
        }

        return array_filter($out, fn ($v) => $v !== [] && $v !== null);
    }

    /** @return list<array<string, mixed>> */
    public static function steps(PlanDetails $plan, ?string $parentId, int $depth = 0): array
    {
        $rows = [];
        foreach ($plan->steps($parentId) as $step) {
            $rows[] = array_filter([
                'id' => $step->id,
                'task' => $step->title,
                'state' => self::state($plan, $step),
                'due' => $step->dueOn,
                'priority' => $step->priority,
                'sub_tasks' => $depth < 2 ? self::steps($plan, $step->id, $depth + 1) : [],
            ], fn ($v) => $v !== null && $v !== []);
        }

        return $rows;
    }

    public static function state(PlanDetails $plan, PlanItem $item): string
    {
        return self::words($plan->stateOf($item));
    }

    /** The plan's states as the screens say them. */
    public static function words(string $state): string
    {
        return ['todo' => 'to do', 'doing' => 'in progress', 'not_yet' => 'not yet'][$state] ?? $state;
    }
}
