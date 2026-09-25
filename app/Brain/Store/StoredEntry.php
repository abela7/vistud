<?php

namespace App\Brain\Store;

use App\Brain\Journal\JournalEntry;

/** One stored entry with its content. Content is empty, and `blocked` true, after a redaction. */
final readonly class StoredEntry
{
    /** @param array<string, string> $content */
    public function __construct(
        public JournalEntry $entry,
        public array $content,
        public bool $blocked,
    ) {}
}
