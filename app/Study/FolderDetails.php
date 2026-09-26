<?php

namespace App\Study;

/** A folder, as the screens see it. $parentId is null for a folder directly in its module. */
final readonly class FolderDetails
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $moduleId,
        public ?string $parentId,
        public string $name,
        public int $depth,
        public int $position,
    ) {}

    /** The list this folder sits in: its parent folder, or its module. */
    public function siblingsKey(): string
    {
        return $this->parentId === null ? "module:{$this->moduleId}" : "folder:{$this->parentId}";
    }
}
