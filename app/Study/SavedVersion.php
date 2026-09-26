<?php

namespace App\Study;

/** What a save answers: the note's version now, and when it was saved. */
final readonly class SavedVersion
{
    public function __construct(
        public int $version,
        public string $savedAt,
    ) {}
}
