<?php

namespace App\Engine\Tools;

use App\Platform\Access\Principal;
use App\Study\AssignmentSource;
use App\Study\AssignmentSources;
use App\Study\AssignmentUnderstandings;
use App\Study\FileDetails;

/** Reads the full text of one written source of the assignment, by the label its understanding cites, or by its title. */
final class AssignmentSourceTool implements Tool
{
    public function __construct(private AssignmentSources $sources, private AssignmentUnderstandings $understandings) {}

    public function name(): string
    {
        return 'assignment_source';
    }

    public function description(): string
    {
        return 'Reads the full text of one written source of the assignment (the brief, a rubric, an email, teacher feedback, a note or a correction). Give its label (e.g. S1, S2) or part of its title.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'source' => ['type' => 'string', 'description' => 'The source label (e.g. S1, S2) or part of its title or kind.'],
            ],
            'required' => ['source'],
            'additionalProperties' => false,
        ];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        if ($context->activityId === null) {
            return 'This can only be done in an assignment\'s chat.';
        }

        $wanted = trim(Lookup::text($input, 'source') ?? '');
        if ($wanted === '') {
            return 'Say which source to read (e.g. S1, or part of its title).';
        }

        // The labels the understanding cites (AssignmentSources::labelled), so S2 here is the S2 it means.
        $labelled = $this->sources->labelled($by, $context->activityId, $this->understandings->latest($by, $context->activityId));
        $written = array_filter($labelled, fn ($item) => $item instanceof AssignmentSource);
        if (preg_match('/^F\d+$/i', $wanted) === 1 && ($file = $labelled[strtoupper($wanted)] ?? null) instanceof FileDetails) {
            return "{$wanted} is the file “{$file->fileName()}”: read it with read_file.";
        }
        if ($written === []) {
            return 'This assignment has no written sources.';
        }

        $label = isset($written[strtoupper($wanted)]) ? strtoupper($wanted) : null;
        if ($label === null) {
            $lower = mb_strtolower($wanted);
            $matches = array_keys(array_filter($written, fn (AssignmentSource $s) => str_contains(mb_strtolower($s->name()), $lower) || str_contains(mb_strtolower($s->kindLabel()), $lower)));
            if (count($matches) > 1) {
                return "Several sources match \"{$wanted}\": ".implode(', ', array_map(fn ($l) => "{$l} ({$written[$l]->name()})", $matches)).'. Give its label.';
            }
            $label = $matches[0] ?? null;
        }
        if ($label === null) {
            return "No source matching \"{$wanted}\". Available sources: ".implode(', ', array_map(fn ($l) => "{$l} ({$written[$l]->name()})", array_keys($written))).'.';
        }

        $found = $written[$label];
        $header = "### {$label} · {$found->kindLabel()}".($found->title !== null ? " · “{$found->title}”" : '').($found->receivedOn !== null ? " · received {$found->receivedOn}" : '');

        return "{$header}\n\n{$found->text}";
    }
}
