<?php

namespace App\Console\Commands;

use App\Platform\Access\LearnerKeyStore;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\MasterKeys;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\AppError;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ProvisionLearnerKeys extends Command
{
    protected $signature = 'vistud:keys:provision {--dry-run} {--prune-orphans}';

    protected $description = 'Provision never-provisioned learners; report missing keys and orphan key rows.';

    public function handle(LearnerKeyStore $store, MasterKeys $masters): int
    {
        if (! app()->runningUnitTests() && ! app()->isDownForMaintenance()) {
            $this->error('Put the app in maintenance and stop workers before provisioning keys.');

            return self::FAILURE;
        }
        $masters->ring();
        $dry = (bool) $this->option('dry-run');
        $failed = 0;
        foreach (DB::table('learners')->join('users', 'users.id', '=', 'learners.user_id')
            ->where('users.status', '!=', 'deleted')->select('learners.*')->orderBy('learners.id')->cursor() as $learner) {
            $scope = LearnerScope::forJob($learner->id);
            $exists = LearnerTables::query($scope, 'learner_keys')->exists();
            if ($learner->key_provisioned_at !== null && ! $exists) {
                $this->error('Missing previously provisioned key: '.$learner->id);
                $failed++;

                continue;
            }
            if ($exists) {
                try {
                    $store->load($scope);
                } catch (AppError) {
                    $this->error('Unreadable key: '.$learner->id);
                    $failed++;

                    continue;
                }
            }
            $this->line(($exists ? 'Keep: ' : 'Provision: ').$learner->id);
            if (! $dry) {
                if (! $exists) {
                    $store->provision($scope);
                }
                DB::table('learners')->where('id', $learner->id)->whereNull('key_provisioned_at')
                    ->update(['key_provisioned_at' => now()]);
            }
        }
        // Maintenance + stopped workers prevent a live signup racing this cross-database check.
        // A one-hour grace period also protects a recently interrupted creation.
        foreach (LearnerTables::keyRowsForMaintenance(Principal::system())->orderBy('learner_id')->cursor() as $row) {
            if (DB::table('learners')->where('id', $row->learner_id)->exists()) {
                continue;
            }
            $this->line('Orphan: '.$row->learner_id);
            if (! $dry && $this->option('prune-orphans') && $row->created_at < now()->subHour()->toDateTimeString()) {
                LearnerTables::query(LearnerScope::forJob($row->learner_id), 'learner_keys')->delete();
                $this->line('Removed orphan: '.$row->learner_id);
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
