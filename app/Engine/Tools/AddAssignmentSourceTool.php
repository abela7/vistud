<?php

namespace App\Engine\Tools;

use App\Engine\Jobs\Runner;
use App\Engine\Jobs\UnderstandAssignment;
use App\Platform\Access\Principal;
use App\Study\AssignmentSources;

/** Adds a new written source to the assignment. */
final class AddAssignmentSourceTool implements Tool
{
    public function __construct(private AssignmentSources $sources, private Runner $runner) {}

    public function name(): string
    {
        return 'add_assignment_source';
    }

    public function description(): string
    {
        return 'Adds a new written source to the assignment (what the teacher said, an email, a rubric, an announcement, feedback, or a student note). Requires the student\'s agreement. Adding a source triggers the AI to update its understanding.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'kind' => [
                    'type' => 'string',
                    'enum' => ['brief', 'rubric', 'email', 'announcement', 'feedback', 'note', 'correction', 'other'],
                    'description' => 'The kind of source.',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'A short title for the source (e.g. "Email from Prof. Smith on 12 Oct").',
                ],
                'text' => [
                    'type' => 'string',
                    'description' => 'The full text of the source.',
                ],
            ],
            'required' => ['kind', 'text'],
            'additionalProperties' => false,
        ];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        if ($context->activityId === null) {
            return 'This can only be done in an assignment\'s chat.';
        }

        $kind = Lookup::text($input, 'kind') ?? 'other';
        $title = Lookup::text($input, 'title');
        $text = Lookup::text($input, 'text');

        if ($text === null || trim($text) === '') {
            return 'Give the text of the source.';
        }

        $displayTitle = $title !== null && trim($title) !== '' ? trim($title) : (AssignmentSources::KINDS[$kind] ?? 'Source');
        $changeKey = 'add_assignment_source:'.sha1($kind.':'.$displayTitle.':'.trim($text));
        $question = "Keep \"{$displayTitle}\" as a source for the assignment? The AI will update its understanding from it.";

        if (($ask = Agreement::needed($by, $context, $changeKey, $question)) !== null) {
            return $ask;
        }

        $source = $this->sources->add($by, $context->activityId, [
            'kind' => $kind,
            'title' => $title !== null && trim($title) !== '' ? trim($title) : null,
            'text' => trim($text),
        ]);

        $pending = $this->runner->pending($by, 'understand_assignment', 'activity', $context->activityId);
        if ($pending === null) {
            $this->runner->start($by, new UnderstandAssignment($context->workspaceId, $context->activityId));
        }

        $context->effects->sourceKept($source->name());

        return "Added source \"{$source->name()}\". The AI is updating its understanding.";
    }
}
