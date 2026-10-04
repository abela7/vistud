<?php

namespace App\Engine\Jobs;

use App\Platform\Access\Principal;

/**
 * A job the student waits for, because they tapped it and want the answer on screen (docs/specs/vistud-2-blueprint.md
 * §3.6.5): make cards from a file, write a note from it, answer a question from the notes. It hands its result back
 * (Runner::answer); the caller shows it and keeps what the student keeps. Like any job it is described by ids alone and
 * reads the student's material through the services when it runs.
 */
abstract class Answering extends Job
{
    final public function handle(Principal $by, Run $run): void
    {
        $this->answer($by, $run);
    }

    /** The work, as the student, and what it came to. */
    abstract public function answer(Principal $by, Run $run): mixed;
}
