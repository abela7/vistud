<?php

namespace App\Study;

/**
 * A folder, as the screens see it. $parentId is null for a folder at the top
 * of its module, or (with $moduleId null too) at its workspace's top level.
 */
final readonly class FolderDetails
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public ?string $moduleId,
        public ?string $parentId,
        public string $name,
        public int $depth,
        public int $position,
    ) {}

    /** The list this folder sits in: its parent folder, its module, or its workspace's top level. */
    public function siblingsKey(): string
    {
        return Input::placeKey($this->workspaceId, $this->moduleId, $this->parentId);
    }
}
