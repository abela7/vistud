<?php

namespace App\Platform\Access;

use App\Platform\Errors\Forbidden;

/**
 * The one learner stream a piece of data access may touch (ADR 0001 I3).
 * Every learner-scoped query takes one of these as a required argument; see
 * App\Platform\Database\LearnerTables.
 *
 * Stable contract: docs/architecture/contracts.md.
 */
final readonly class LearnerScope
{
    private function __construct(public string $learnerId) {}

    /** The acting user's own stream. Needs the student role; being an admin grants nothing. */
    public static function of(Principal $principal): self
    {
        if ($principal->isSystem() || ! $principal->hasRole(Role::Student) || $principal->learnerId === null) {
            throw new Forbidden('student_role_required', 'This needs a student account.');
        }

        return new self($principal->learnerId);
    }

    /**
     * For queued jobs and maintenance commands that were handed a learner ID
     * by trusted server code (never by a request). Callers are restricted by
     * tests/Architecture/LearnerIsolationTest.php.
     */
    public static function forJob(string $learnerId): self
    {
        return new self($learnerId);
    }
}
