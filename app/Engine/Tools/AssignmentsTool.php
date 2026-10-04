<?php

namespace App\Engine\Tools;

use App\Platform\Access\Principal;
use App\Study\Activities;
use App\Study\Modules;
use App\Study\Plans;
use Carbon\CarbonImmutable;

/** Every assignment, quiz, exam, lab and task: when it is due, where it stands, how far its plan has got and how it is going. */
final class AssignmentsTool implements Tool
{
    public function __construct(private Activities $activities, private Plans $plans, private Modules $modules) {}

    public function name(): string
    {
        return 'assignments';
    }

    public function description(): string
    {
        return 'The student\'s assignments, quizzes, exams, labs and tasks: when each is due, its status (to do, in progress, done), how far its plan has got and how it is going (on track, at risk, off track). Use assignment_plan for the steps of one.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => ['which' => ['type' => 'string', 'enum' => ['open', 'all'], 'description' => 'open (the default: not done) or all']], 'additionalProperties' => false];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        $all = ($input['which'] ?? 'open') === 'all';
        $modules = collect($this->modules->list($by, $context->workspaceId))->pluck('title', 'id');
        $activities = array_values(array_filter($this->activities->list($by, $context->workspaceId), fn ($a) => $all || $a->status !== 'done'));
        $standing = $this->plans->standing($by, $context->workspaceId, $activities);
        $now = CarbonImmutable::now($context->zone);
        usort($activities, fn ($a, $b) => [$a->dueOn === null, $a->dueOn] <=> [$b->dueOn === null, $b->dueOn]);
        $rows = [];
        foreach (array_slice($activities, 0, 40) as $a) {
            $progress = $standing['progress'][$a->id] ?? null;
            $health = $standing['health'][$a->id] ?? null;
            $rows[] = array_filter([
                'title' => $a->title,
                'kind' => strtolower($a->kindLabel()),
                'status' => ['todo' => 'to do', 'doing' => 'in progress', 'done' => 'done'][$a->status] ?? $a->status,
                'due' => $a->dueOn,
                'time_left' => $a->status !== 'done' ? $a->timeLeft($now) : null,
                'module' => $a->moduleId !== null ? $modules[$a->moduleId] ?? null : null,
                'plan' => $progress ? "{$progress->done} of {$progress->total} done, {$progress->percent}%" : null,
                'going' => $health ? str_replace('_', ' ', $health['state']).($health['reasons'] ? ' ('.implode('; ', $health['reasons']).')' : '') : null,
            ]);
        }

        return $rows === [] ? ($all ? 'No assignments or tasks yet.' : 'Nothing open: no assignments or tasks to do.') : Lookup::json($rows);
    }
}
