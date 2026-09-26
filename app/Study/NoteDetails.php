<?php

namespace App\Study;

/**
 * A note, as the screens see it. $doc (the editor's JSON) is filled only
 * when one note is opened, not in lists. $moduleId and $folderId say where
 * it is; both null is the workspace's top level.
 */
final readonly class NoteDetails
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public ?string $moduleId,
        public ?string $folderId,
        public string $title,
        public int $version,
        public int $position,
        public string $updatedAt,
        public ?string $trashedAt,
        public ?array $doc = null,
    ) {}

    /** The title to show: an untitled note still needs a name. */
    public function displayTitle(): string
    {
        return $this->title === '' ? 'Untitled note' : $this->title;
    }

    /** The list this note sits in: its folder, its module, or its workspace's top level. */
    public function placeKey(): string
    {
        return Input::placeKey($this->workspaceId, $this->moduleId, $this->folderId);
    }
}
