<?php

namespace App\Engine\Context;

/**
 * What a call to the engine carries as its standing context (docs/specs/vistud-2-blueprint.md §3.6.2): the system
 * prompt, the tools beside it, and a report of every layer with what it weighs and what had to be cut. `stable` is
 * the front of the system prompt that does not change from one session of a course to the next (the role's
 * rules, the course, the student), the part a service can cache.
 */
final readonly class Built
{
    /**
     * @param  list<array<string, mixed>>  $tools  the tool definitions, in the OpenAI chat format
     * @param  list<array{layer: int, name: string, tokens: int, budget: ?int, cut: int}>  $report  by layer, in order
     */
    public function __construct(
        public string $system,
        public string $stable,
        public array $tools,
        public array $report,
    ) {}

    /** What the standing context weighs before the conversation, in tokens. */
    public function tokens(): int
    {
        return array_sum(array_column($this->report, 'tokens'));
    }

    /**
     * The layers that had lines cut, as `layer => number of lines`.
     *
     * @return array<int, int>
     */
    public function cuts(): array
    {
        $cuts = [];
        foreach ($this->report as $layer) {
            if ($layer['cut'] > 0) {
                $cuts[$layer['layer']] = $layer['cut'];
            }
        }

        return $cuts;
    }
}
