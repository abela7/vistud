<?php

namespace App\Study;

/**
 * What the reader wrote about a file (docs/specs/vistud-2-blueprint.md §3.5.3): a short summary, an outline, the topics it
 * covers and its language, or why there is none (a picture, or a file with no words in it).
 */
final readonly class FileDigestDetails
{
    /**
     * @param  list<array{page: int, heading: string}>  $outline
     * @param  list<string>  $topics
     */
    public function __construct(
        public string $id,
        public string $fileId,
        public string $status,
        public ?string $reason,
        public string $summary = '',
        public array $outline = [],
        public array $topics = [],
        public ?string $language = null,
        public int $pages = 0,
        public int $chars = 0,
        public ?string $model = null,
        public int $costMicros = 0,
        public string $createdAt = '',
    ) {}

    /** Whether the reader read it (rather than finding nothing to read). */
    public function read(): bool
    {
        return $this->status === 'done';
    }
}
