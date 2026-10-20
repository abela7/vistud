<?php

namespace App\Console\Commands;

use App\Platform\Access\LearnerKeyStore;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\MasterKeys;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use Illuminate\Console\Command;

final class RotateLearnerMasterKey extends Command
{
    protected $signature = 'vistud:keys:rotate';

    protected $description = 'Resume wrapping learner keys with the active environment master key.';

    public function handle(LearnerKeyStore $store, MasterKeys $masters): int
    {
        // Validate even on an empty database. Never take a secret in a CLI argument.
        $masters->ring();
        $rotated = 0;
        foreach (LearnerTables::keyRowsForMaintenance(Principal::system())->orderBy('learner_id')->cursor() as $row) {
            if ($store->rotate(LearnerScope::forJob($row->learner_id))) {
                $rotated++;
            }
        }
        $this->line('Rewrapped keys: '.$rotated);

        return self::SUCCESS;
    }
}
