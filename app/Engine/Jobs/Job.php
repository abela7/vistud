<?php

namespace App\Engine\Jobs;

use App\Engine\Role;
use App\Platform\Access\Principal;

/**
 * One piece of the reader's or the helper's work (docs/specs/vistud-2-blueprint.md §3.6.3): reading a file,
 * building a course profile, writing a note from a file. A job is described by ids alone, which course and
 * what to work on, because it is queued or run after the response: it reads the student's material through the
 * services when it runs, never from its own payload (a queue is not a learner table), and it keeps its result
 * through the services too. The Runner records the run and its cost, and an engine call is made with
 * `$run->ask()`, which counts it and stops at the month's limit. Start one with Runner::start().
 */
abstract class Job
{
    public function __construct(
        public readonly string $workspaceId,
        public readonly ?string $targetType = null,
        public readonly ?string $targetId = null,
    ) {}

    /** What the job is, as `engine_jobs.kind` says it: read_file, profile_course, note_from_file, cards_from… */
    abstract public function kind(): string;

    /** The role whose model does it: the reader's, unless a job says otherwise. */
    public function role(): Role
    {
        return Role::Reader;
    }

    /**
     * The work, as the student. A refusal or an engine failure raised here is recorded on the run; `$run->skip()`
     * ends it as skipped.
     */
    abstract public function handle(Principal $by, Run $run): void;
}
