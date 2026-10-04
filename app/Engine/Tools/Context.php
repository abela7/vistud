<?php

namespace App\Engine\Tools;

/** Where a chat stands: the workspace it is in, the module and session when there are ones, and the student's time zone. */
final readonly class Context
{
    public function __construct(public string $workspaceId, public ?string $moduleId, public ?string $sessionId, public string $zone) {}
}
