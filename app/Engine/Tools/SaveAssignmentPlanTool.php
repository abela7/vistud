<?php

declare(strict_types=1);

namespace App\Engine\Tools;

use App\Platform\Access\Principal;
use App\Study\Activities;
use App\Study\AssignmentLog;
use App\Study\Plans;
use App\Study\PlanStarters;

/**
 * Saves the agreed step-by-step plan, marking criteria, and tracking method for an assignment directly from the chat.
 */
final class SaveAssignmentPlanTool implements Tool
{
    private const TRACKING_MODES = ['steps', 'criteria', 'both'];

    public function __construct(
        private Plans $plans,
        private Activities $activities,
        private AssignmentLog $log,
    ) {}

    public function name(): string
    {
        return 'save_assignment_plan';
    }

    public function description(): string
    {
        return 'Saves the agreed assignment plan (from a pre-made starter template and/or custom sections, steps, criteria, milestones) and tracking method (steps, criteria, or both).';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'tracking' => [
                    'type' => 'string',
                    'enum' => self::TRACKING_MODES,
                    'description' => 'Track progress by "steps", "criteria", or "both".',
                ],
                'starter' => [
                    'type' => 'string',
                    'enum' => array_keys(PlanStarters::ALL),
                    'description' => 'Optional pre-made plan starter key (essay, problems, presentation, coding, lab, project, group, exam) to apply as the base structure.',
                ],
                'replace' => [
                    'type' => 'boolean',
                    'description' => 'Replace existing plan items before saving (default false).',
                ],
                'sections' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'marks' => ['type' => 'integer'],
                            'steps' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                        'required' => ['title'],
                        'additionalProperties' => false,
                    ],
                ],
                'steps' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'criteria' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'marks' => ['type' => 'integer'],
                        ],
                        'required' => ['title'],
                        'additionalProperties' => false,
                    ],
                ],
                'milestones' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'due_on' => ['type' => 'string'],
                        ],
                        'required' => ['title'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['tracking'],
            'additionalProperties' => false,
        ];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        if ($context->activityId === null) {
            return 'This can only be done in an assignment\'s chat.';
        }

        $tracking = Lookup::text($input, 'tracking') ?? 'both';
        if (! in_array($tracking, self::TRACKING_MODES, true)) {
            $tracking = 'both';
        }
        $starterKey = Lookup::text($input, 'starter');
        $starter = $starterKey !== null ? PlanStarters::get($starterKey) : null;
        if ($starterKey !== null && $starter === null) {
            return 'Unknown pre-made plan starter. Choose one of: '.implode(', ', array_keys(PlanStarters::ALL)).'.';
        }
        $replace = (bool) ($input['replace'] ?? false);

        $parts = [];
        foreach (is_array($input['sections'] ?? null) ? $input['sections'] : [] as $rawPart) {
            if (! is_array($rawPart)) {
                continue;
            }
            $title = trim((string) ($rawPart['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $steps = [];
            foreach (is_array($rawPart['steps'] ?? null) ? $rawPart['steps'] : [] as $rawStep) {
                $stepTitle = is_string($rawStep) ? trim($rawStep) : '';
                if ($stepTitle !== '') {
                    $steps[] = $stepTitle;
                }
            }
            $marks = isset($rawPart['marks']) && is_numeric($rawPart['marks']) ? max(1, min(100, (int) $rawPart['marks'])) : null;
            $parts[] = ['title' => $title, 'marks' => $marks, 'steps' => $steps];
        }

        $standaloneSteps = [];
        foreach (is_array($input['steps'] ?? null) ? $input['steps'] : [] as $rawStep) {
            $stepTitle = is_string($rawStep) ? trim($rawStep) : '';
            if ($stepTitle !== '') {
                $standaloneSteps[] = $stepTitle;
            }
        }

        $criteria = [];
        foreach (is_array($input['criteria'] ?? null) ? $input['criteria'] : [] as $rawCrit) {
            if (! is_array($rawCrit)) {
                continue;
            }
            $title = trim((string) ($rawCrit['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $marks = isset($rawCrit['marks']) && is_numeric($rawCrit['marks']) ? max(1, min(100, (int) $rawCrit['marks'])) : null;
            $criteria[] = ['title' => $title, 'marks' => $marks];
        }

        $milestones = [];
        foreach (is_array($input['milestones'] ?? null) ? $input['milestones'] : [] as $rawMilestone) {
            if (! is_array($rawMilestone)) {
                continue;
            }
            $title = trim((string) ($rawMilestone['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $dueOn = isset($rawMilestone['due_on']) && is_string($rawMilestone['due_on']) && trim($rawMilestone['due_on']) !== ''
                ? trim($rawMilestone['due_on'])
                : null;
            $milestones[] = ['title' => $title, 'due_on' => $dueOn];
        }

        if ($starter === null && $parts === [] && $standaloneSteps === [] && $criteria === [] && $milestones === []) {
            return 'Include a pre-made starter or at least one section, step, or marking criterion to save in the plan.';
        }

        $payload = [
            'parts' => $parts,
            'steps' => $standaloneSteps,
            'criteria' => $criteria,
            'milestones' => $milestones,
        ];

        $changeKey = 'save_assignment_plan:'.sha1(json_encode([$replace, $tracking, $starterKey, $payload]));
        $starterNote = $starter !== null ? " (starting from the “{$starter['label']}” template)" : '';
        $question = "Save this assignment plan{$starterNote} and track progress by ".match ($tracking) {
            'steps' => 'plan steps',
            'criteria' => 'marking criteria',
            default => 'both plan steps and marking criteria',
        }.'?';

        if (($ask = Agreement::needed($by, $context, $changeKey, $question)) !== null) {
            return $ask;
        }

        if ($replace) {
            $this->plans->clear($by, $context->activityId);
        }

        $added = 0;
        if ($starter !== null) {
            $added += $this->plans->applyStarter($by, $context->activityId, $starterKey);
        }
        if ($parts !== [] || $standaloneSteps !== [] || $criteria !== [] || $milestones !== []) {
            $added += $this->plans->addAll($by, $context->activityId, $payload);
        }

        $trackingWords = match ($tracking) {
            'steps' => 'plan steps',
            'criteria' => 'marking criteria',
            default => 'plan steps and marking criteria',
        };
        $entry = $this->log->add(
            $by,
            $context->activityId,
            'plan',
            'decision',
            "Plan saved ({$added} items). Tracking method: {$trackingWords}.",
            byRole: 'tutor',
        );
        $context->effects->logEntry($entry->id, $entry->stage, $entry->kind, $entry->text);
        $context->effects->planSaved($added, $tracking);

        $current = $this->activities->find($by, $context->activityId);
        if (in_array($current->stage, ['understand', 'plan'], true)) {
            $this->activities->setStage($by, $context->activityId, 'do');
            $context->effects->stageChanged($current->stage, 'do');
        }

        return "Saved the assignment plan ({$added} items) with tracking by {$trackingWords}, and moved to the \"do\" stage.";
    }
}
