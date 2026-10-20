<?php

namespace App\Console\Commands;

use Illuminate\Cache\DatabaseStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class ClearLearnerTextCaches extends Command
{
    protected $signature = 'vistud:encrypt:clear-text-caches {--dry-run}';

    protected $description = 'Clear only ChooseTopics and Troubles learner caches before encrypted refill.';

    public function handle(): int
    {
        if (! $this->option('dry-run') && ! app()->runningUnitTests() && ! app()->isDownForMaintenance()) {
            $this->error('Put the app in maintenance and stop workers first.');

            return self::FAILURE;
        }
        // The database cache includes entries whose folders/modules were
        // deleted: enumeration from current study rows would miss those.
        $store = Cache::getStore();
        if ($store instanceof DatabaseStore) {
            $connection = $store->getConnection();
            $table = config('cache.stores.database.table', 'cache');
            $prefix = $store->getPrefix();
            $query = $connection->table($table)->where(function ($q) use ($prefix) {
                foreach (['topic-again:', 'topic-review:', 'topic-files-out:', 'engine-trouble:'] as $kind) {
                    $q->orWhere('key', 'like', addcslashes($prefix.$kind, '%_\\').'%');
                }
            });
            $count = $query->count();
            if (! $this->option('dry-run')) {
                $query->delete();
            }
            $this->line('learner cache entries='.$count);

            return self::SUCCESS;
        }
        // Production uses the database cache. Refuse an unenumerable driver;
        // never flush unrelated caches, sessions or locks.
        $this->error('Use the configured database cache store to clear legacy learner text caches.');

        return self::FAILURE;
    }
}
