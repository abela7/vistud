<?php

namespace App\Brain\Writer;

use App\Brain\Journal\JournalEntry;

/** The outcome of appending one entry: recorded now, or a duplicate of one already stored. */
final readonly class AppendResult
{
    public const RECORDED = 'recorded';

    public const DUPLICATE = 'duplicate';

    public function __construct(
        public string $status,
        public JournalEntry $entry,
    ) {}

    public function position(): int
    {
        return $this->entry->position;
    }
}
