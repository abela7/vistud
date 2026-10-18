<?php

namespace App\Platform\Access;

/**
 * Where a request opens the student's own encryption keys, once per-learner encryption exists (ADR 0001; the
 * second-learner gate, docs/specs/mcp-connections-plan.md M6.9). Every MCP call passes through it already, so that
 * work has one place to land. Until then it does nothing.
 */
final class LearnerKeys
{
    public static function unlock(Principal $principal): void {}
}
