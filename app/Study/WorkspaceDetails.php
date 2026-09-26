<?php

namespace App\Study;

/** A student's workspace, as the screens see it (docs/specs/workspaces.md). */
final readonly class WorkspaceDetails
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $code,
        public ?string $term,
        public ?string $startsOn,
        public ?string $endsOn,
        public string $colour,
        public string $icon,
        public string $type,
        public int $position,
        public ?string $archivedAt,
    ) {}

    public function archived(): bool
    {
        return $this->archivedAt !== null;
    }

    /** The line under the name: "BIO101 · Autumn 2026". */
    public function subtitle(): string
    {
        return implode(' · ', array_filter([$this->code, $this->term]));
    }
}
