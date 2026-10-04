<?php

namespace App\Study;

/**
 * What the tutor is told about a module besides its title and topics (docs/specs/vistud-2-blueprint.md §3.6.2, layer 4):
 * its files with a line on each, its open questions, its key points and where the last session stopped. One short
 * line each, so the layer stays within its budget.
 */
final readonly class ModuleBrief
{
    /**
     * @param  list<string>  $files  "Lecture 3.pdf (18 pages: scheduling algorithms, round robin)"
     * @param  list<string>  $questions  "\"Why does round robin starve long jobs?\" (stuck)"
     * @param  list<string>  $keyPoints
     */
    public function __construct(
        public array $files = [],
        public array $questions = [],
        public array $keyPoints = [],
        public ?string $last = null,
    ) {}

    public function empty(): bool
    {
        return $this->files === [] && $this->questions === [] && $this->keyPoints === [] && $this->last === null;
    }

    /** @return array{files: list<string>, questions: list<string>, keyPoints: list<string>, last: ?string} */
    public function toArray(): array
    {
        return ['files' => $this->files, 'questions' => $this->questions, 'keyPoints' => $this->keyPoints, 'last' => $this->last];
    }

    public static function fromArray(array $data): self
    {
        $lines = fn (mixed $list) => is_array($list) ? array_values(array_map('strval', $list)) : [];

        return new self($lines($data['files'] ?? []), $lines($data['questions'] ?? []), $lines($data['keyPoints'] ?? []), isset($data['last']) ? (string) $data['last'] : null);
    }
}
