<?php

namespace App\Study;

/** A module of a workspace, as the screens see it. */
final readonly class ModuleDetails
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $title,
        public ?string $startsOn,
        public ?string $endsOn,
        public int $position,
    ) {}
}
