<?php

namespace App\Jobs;

use App\Engine\Jobs\Job;
use App\Engine\Jobs\Runner;
use App\Identity\PrincipalFactory;
use App\Models\User;
use App\Platform\Errors\AppError;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Carries one of the reader's or the helper's jobs (App\Engine\Jobs\Job) to the queue's worker, or to the end of
 * the request that started it, and runs it as the student who started it (App\Engine\Jobs\Runner). It holds ids only:
 * the row that records the run, the account, and the job's own ids. Tried once; a failure is on the row, and the
 * screen offers to try again.
 */
final class RunEngineJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** A model that thinks for a long time gets this long. */
    public int $timeout = 300;

    public function __construct(public string $rowId, public string $userId, public Job $work) {}

    public function handle(Runner $runner, PrincipalFactory $principals): void
    {
        $user = User::query()->find($this->userId);
        if ($user === null) {
            return;
        }
        try {
            $by = $principals->forUser($user, 'job');
        } catch (AppError) {
            // Suspended or deleted since it was queued: nothing runs for them.
            return;
        }
        $runner->execute($this->rowId, $by, $this->work);
    }
}
