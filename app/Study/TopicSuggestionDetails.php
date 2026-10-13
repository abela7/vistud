<?php

namespace App\Study;

/** A topic the reader found in a module's files, waiting for the student to add it or dismiss it. */
final readonly class TopicSuggestionDetails
{
    /**
     * @param  list<string>  $covers  the smaller ideas it holds
     * @param  list<array{fileId: string, name: string, pages: string}>  $sources  where it is taught, when the files are still there
     */
    public function __construct(
        public string $id,
        public string $moduleId,
        public string $name,
        /** The file it was found in, when that file is still there. */
        public ?string $sourceFileId = null,
        public ?string $sourceName = null,
        /** The folder of the file it was found in (docs/specs/vistud-2-blueprint.md, Phase 9): added, the topic goes there. */
        public ?string $folderId = null,
        /** Why it matters, when the AI chose it from all of a part's files (App\Engine\Jobs\ChooseTopics). */
        public ?string $reason = null,
        public array $covers = [],
        public array $sources = [],
        /** Its place in the order to study the part's topics, from 1. */
        public ?int $position = null,
        /** The AI's note on why it chose it: the evidence in the files, and what it folded in. */
        public ?string $note = null,
    ) {}
}
