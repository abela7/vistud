<?php

namespace App\Engine\Tools;

use App\Platform\Access\Principal;
use App\Study\Activities;
use App\Study\AssignmentLog;

/** Records an entry in the assignment log. */
final class LogProgressTool implements Tool
{
    public function __construct(private AssignmentLog $log, private Activities $activities) {}

    public function name(): string
    {
        return 'log_progress';
    }

    public function description(): string
    {
        return 'Records an entry in the assignment log: a decision made, progress achieved, important advice given, feedback received, or a risk identified. Max 500 characters.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'stage' => [
                    'type' => 'string',
                    'enum' => Activities::STAGES,
                    'description' => 'The stage of the assignment (understand, plan, do, review, submit).',
                ],
                'kind' => [
                    'type' => 'string',
                    'enum' => AssignmentLog::KINDS,
                    'description' => 'The kind of entry (decision, progress, advice, feedback, risk).',
                ],
                'text' => [
                    'type' => 'string',
                    'description' => 'A concise summary of what was decided, achieved, advised, or noted (max 500 chars).',
                ],
            ],
            'required' => ['stage', 'kind', 'text'],
            'additionalProperties' => false,
        ];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        if ($context->activityId === null) {
            return 'This can only be done in an assignment\'s chat.';
        }

        $stage = Lookup::text($input, 'stage') ?? $this->activities->find($by, $context->activityId)->stage;
        $kind = Lookup::text($input, 'kind') ?? 'progress';
        $text = Lookup::text($input, 'text');

        if ($text === null || trim($text) === '') {
            return 'Give the text to log.';
        }

        $entry = $this->log->add($by, $context->activityId, $stage, $kind, trim($text), byRole: 'tutor');

        $context->effects->logEntry($entry->id, $entry->stage, $entry->kind, $entry->text);

        return "Logged {$entry->kindLabel()}: \"{$entry->text}\".";
    }
}
