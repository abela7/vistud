<?php

namespace App\Study;

/** A topic the reader found in a module's files, waiting for the student to add it or dismiss it. */
final readonly class TopicSuggestionDetails
{
    public function __construct(
        public string $id,
        public string $moduleId,
        public string $name,
        /** The file it was found in, when that file is still there. */
        public ?string $sourceFileId = null,
        public ?string $sourceName = null,
    ) {}
}
