<?php

namespace App\Console\Commands;

use App\Platform\Access\LearnerScope;
use App\Study\Notes;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deletes notes that have been in the trash for more than 30 days
 * (docs/specs/workspaces.md). Runs daily from the scheduler.
 */
#[Signature('vistud:notes:purge-trash')]
#[Description('Delete notes that have been in the trash for more than 30 days')]
class PurgeNoteTrash extends Command
{
    public function handle(Notes $notes): int
    {
        $deleted = 0;
        foreach (DB::table('learners')->pluck('id') as $learnerId) {
            $deleted += $notes->purgeTrash(LearnerScope::forJob((string) $learnerId));
        }
        $this->info("Deleted {$deleted} ".($deleted === 1 ? 'note' : 'notes').' from the trash.');

        return self::SUCCESS;
    }
}
