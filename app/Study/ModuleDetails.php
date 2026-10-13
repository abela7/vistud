<?php

namespace App\Study;

/** A module of a workspace, as the screens see it: any group studied together, with a line of what it is about. */
final readonly class ModuleDetails
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $title,
        public ?string $startsOn,
        public ?string $endsOn,
        public int $position,
        public ?string $about = null,
    ) {}
}
