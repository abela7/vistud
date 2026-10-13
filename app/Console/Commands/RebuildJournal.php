<?php

namespace App\Console\Commands;

use App\Brain\Store\Rebuild;
use App\Identity\AccountFactory;
use App\Models\User;
use App\Platform\Access\LearnerScope;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Checks what is derived from each learner's journal against the journal itself: the reference index, and that the
 * rules replay to the same state every time (App\Brain\Store\Rebuild). With --repair, puts the index right and keeps
 * a fresh snapshot. Fails when anything was off and left so, so a scheduler or CI notices.
 */
#[Signature('vistud:journal:rebuild {email? : One student; every learner when left out} {--repair : Rebuild the reference index and the snapshot}')]
#[Description("Check, and with --repair rebuild, what is derived from learners' journals")]
class RebuildJournal extends Command
{
    public function handle(Rebuild $rebuild): int
    {
        $email = $this->argument('email');
        if (is_string($email) && $email !== '') {
            $user = User::query()->where('email', AccountFactory::normaliseEmail($email))->first();
            $learners = $user === null ? [] : DB::table('learners')->where('user_id', $user->id)->pluck('id')->all();
            if ($learners === []) {
                $this->error('No student account has that email address.');

                return self::FAILURE;
            }
        } else {
            $learners = DB::table('learners')->orderBy('id')->pluck('id')->all();
        }

        $repair = (bool) $this->option('repair');
        $wrong = 0;
        foreach ($learners as $learnerId) {
            $result = $rebuild->run(LearnerScope::forJob((string) $learnerId), $repair);
            $refsOff = $result['missing'] + $result['extra'] > 0;
            $off = $refsOff || ! $result['deterministic'];
            if (($refsOff && ! $result['repaired']) || ! $result['deterministic']) {
                $wrong++;
            }
            if ($off || $this->output->isVerbose()) {
                $this->line(sprintf(
                    '%s: %d entries, %d references (%d missing, %d extra%s), replay %s.',
                    $learnerId, $result['entries'], $result['refs'], $result['missing'], $result['extra'],
                    $result['repaired'] ? ', repaired' : '', $result['deterministic'] ? 'stable' : 'NOT stable',
                ));
            }
        }

        $count = count($learners);
        if ($wrong > 0) {
            $this->error("{$wrong} of {$count} ".($count === 1 ? 'journal' : 'journals').' need attention'.($repair ? '.' : '; run again with --repair to rebuild the index.'));

            return self::FAILURE;
        }
        $this->info("Checked {$count} ".($count === 1 ? 'journal' : 'journals').': '.($repair ? 'rebuilt where needed, snapshots kept.' : 'everything derived matches.'));

        return self::SUCCESS;
    }
}
