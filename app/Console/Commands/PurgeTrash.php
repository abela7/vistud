<?php

namespace App\Console\Commands;

use App\Platform\Access\LearnerScope;
use App\Study\Files;
use App\Study\Notes;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deletes notes and files that have been in the trash for more than 30
 * days (docs/specs/workspaces.md). Runs daily from the scheduler.
 */
#[Signature('vistud:trash:purge')]
#[Description('Delete notes and files that have been in the trash for more than 30 days')]
class PurgeTrash extends Command
{
    public function handle(Notes $notes, Files $files): int
    {
        [$deletedNotes, $deletedFiles] = [0, 0];
        foreach (DB::table('learners')->pluck('id') as $learnerId) {
            $scope = LearnerScope::forJob((string) $learnerId);
            $deletedNotes += $notes->purgeTrash($scope);
            $deletedFiles += $files->purgeTrash($scope);
        }
        $this->info("Deleted {$deletedNotes} ".($deletedNotes === 1 ? 'note' : 'notes')." and {$deletedFiles} ".($deletedFiles === 1 ? 'file' : 'files').' from the trash.');

        return self::SUCCESS;
    }
}
