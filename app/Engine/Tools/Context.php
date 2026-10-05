<?php

namespace App\Engine\Tools;

/**
 * Where a chat stands: the workspace it is in, the module and session when there are ones, the folder the session
 * studies in (docs/specs/vistud-2-blueprint.md, Phase 9: the tools default to it), and the student's time zone; and
 * what the tools did in the course meanwhile (Effects), for the chat to tell.
 */
final readonly class Context
{
    public function __construct(public string $workspaceId, public ?string $moduleId, public ?string $sessionId, public string $zone, public Effects $effects = new Effects, public ?string $folderId = null) {}
}
