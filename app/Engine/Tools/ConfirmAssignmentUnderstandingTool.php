<?php

declare(strict_types=1);

namespace App\Engine\Tools;

use App\Platform\Access\Principal;
use App\Study\Activities;
use App\Study\AssignmentSources;
use App\Study\AssignmentUnderstandings;

/**
 * Confirms the current assignment understanding once the student and tutor have reviewed or clarified it in chat,
 * and moves the assignment from 'understand' to 'plan' when ready.
 */
final class ConfirmAssignmentUnderstandingTool implements Tool
{
    public function __construct(
        private AssignmentUnderstandings $understandings,
        private AssignmentSources $sources,
        private Activities $activities,
    ) {}

    public function name(): string
    {
        return 'confirm_assignment_understanding';
    }

    public function description(): string
    {
        return 'Marks the current assignment understanding as confirmed once the student agrees it is right, and moves to "plan".';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'move_to_plan' => [
                    'type' => 'boolean',
                    'description' => 'Move stage from understand to plan (default true).',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        if ($context->activityId === null) {
            return 'This can only be done in an assignment\'s chat.';
        }

        $latest = $this->understandings->latest($by, $context->activityId);
        if ($latest === null) {
            return 'There is no stored understanding yet. Ask the student to add their brief or files and read the assignment first.';
        }

        if ($this->sources->outOfDate($by, $context->activityId, $latest)) {
            return 'The understanding is out of date because sources were added or changed. Wait for the AI re-read to finish (or ask the student to click Read again) before confirming.';
        }

        $this->understandings->confirm($by, $context->activityId);
        $context->effects->understandingConfirmed($latest->revision);

        $moveToPlan = (bool) ($input['move_to_plan'] ?? true);
        $current = $this->activities->find($by, $context->activityId);
        if ($moveToPlan && $current->stage === 'understand') {
            $this->activities->setStage($by, $context->activityId, 'plan');
            $context->effects->stageChanged('understand', 'plan');

            return "Confirmed understanding (version {$latest->revision}) and moved the assignment to the \"plan\" stage.";
        }

        return "Confirmed understanding (version {$latest->revision}).";
    }
}
