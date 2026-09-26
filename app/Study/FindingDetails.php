<?php

namespace App\Study;

/**
 * A finding, as the screens see it. $source is `note:{id}` or `file:{id}`;
 * $sourceName is null when there is none, or when it has gone to the trash.
 */
final readonly class FindingDetails
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $topicId,
        public string $text,
        public string $author,
        public ?string $source,
        public ?string $sourceName,
        public ?string $locator,
    ) {}

    /** The page of the note or file it came from, or null. */
    public function sourceUrl(): ?string
    {
        if ($this->sourceName === null) {
            return null;
        }
        [$type, $id] = explode(':', (string) $this->source, 2);

        return route($type === 'note' ? 'workspaces.notes.show' : 'workspaces.files.show', [$this->workspaceId, $id]);
    }
}
