<?php

namespace App\Console\Commands;

use App\Identity\PrincipalFactory;
use App\Models\User;
use App\Platform\Access\LearnerScope;
use App\Study\Sessions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Applies the study clock's rules to open sessions (docs/specs/study-memory.md
 * §4): a running session with no activity pauses, a long break ends, and a
 * session left for hours ends and is written to the journal. Reads apply the
 * same rules; this catches the sessions nobody opens again. Every 10 minutes.
 */
#[Signature('vistud:sessions:settle')]
#[Description('Pause idle study sessions, end long breaks, and end sessions left open for hours')]
class SettleSessions extends Command
{
    public function handle(Sessions $sessions, PrincipalFactory $principals): int
    {
        $changed = 0;
        foreach (DB::table('learners')->get(['id', 'user_id']) as $learner) {
            $user = User::query()->find($learner->user_id);
            if ($user !== null) {
                $changed += $sessions->settleAll(LearnerScope::forJob((string) $learner->id), $principals->forUser($user, 'web'));
            }
        }
        $this->info("Settled {$changed} ".($changed === 1 ? 'session' : 'sessions').'.');

        return self::SUCCESS;
    }
}
