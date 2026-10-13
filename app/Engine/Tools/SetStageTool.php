<?php

namespace App\Engine\Tools;

use App\Platform\Access\Principal;
use App\Study\Activities;

/** Updates the assignment's stage after agreement. */
final class SetStageTool implements Tool
{
    public function __construct(private Activities $activities) {}

    public function name(): string
    {
        return 'set_stage';
    }

    public function description(): string
    {
        return 'Updates the current stage of the assignment (understand, plan, do, review, submit). Requires the student\'s agreement.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'stage' => [
                    'type' => 'string',
                    'enum' => Activities::STAGES,
                    'description' => 'The stage to set (understand, plan, do, review, submit).',
                ],
            ],
            'required' => ['stage'],
            'additionalProperties' => false,
        ];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        if ($context->activityId === null) {
            return 'This can only be done in an assignment\'s chat.';
        }

        $stage = Lookup::text($input, 'stage');
        if ($stage === null || ! in_array($stage, Activities::STAGES, true)) {
            return 'Stage must be one of: '.implode(', ', Activities::STAGES).'.';
        }

        $changeKey = "set_stage:{$stage}";
        $question = "Move the assignment to the \"{$stage}\" stage?";

        if (($ask = Agreement::needed($by, $context, $changeKey, $question)) !== null) {
            return $ask;
        }

        $current = $this->activities->find($by, $context->activityId);
        $from = $current->stage;
        $this->activities->setStage($by, $context->activityId, $stage);
        $context->effects->stageChanged($from, $stage);

        return "Moved the assignment to the \"{$stage}\" stage.";
    }
}
