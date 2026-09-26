<?php

namespace App\Study;

/** A web link, as the screens see it. $moduleId and $folderId say where it is; both null is the workspace's top level. */
final readonly class LinkDetails
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public ?string $moduleId,
        public ?string $folderId,
        public string $title,
        public string $url,
        public int $position,
    ) {}

    /** "youtube.com" */
    public function site(): string
    {
        return (string) preg_replace('/^www\./i', '', (string) parse_url($this->url, PHP_URL_HOST));
    }

    public function placeKey(): string
    {
        return Input::placeKey($this->workspaceId, $this->moduleId, $this->folderId);
    }
}
