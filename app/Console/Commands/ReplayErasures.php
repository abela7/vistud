<?php

namespace App\Console\Commands;

use App\Identity\RestoreErasures;
use App\Platform\Access\Principal;
use Illuminate\Console\Command;
use Throwable;

final class ReplayErasures extends Command
{
    protected $signature = 'vistud:erasure:replay {ledger : Latest independent export file} {--head= : Its independently obtained authenticated head} {--dry-run} {--offline-confirmed}';

    protected $description = 'Reapply the latest independent erasure intent before reopening a restore.';

    public function handle(RestoreErasures $restores): int
    {
        try {
            $result = $restores->replay(Principal::system(), (string) $this->argument('ledger'), (string) $this->option('head'), (bool) $this->option('dry-run'), (bool) $this->option('offline-confirmed'));
            $this->line('requests='.$result['requests'].' erased='.$result['erased'].' deferred='.$result['deferred'].' head='.$result['head']);

            return $result['deferred'] === 0 ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            $this->error('Erasure replay refused or incomplete. Keep maintenance enabled and workers stopped; obtain the latest independent ledger/checkpoint and retry.');

            return self::FAILURE;
        }
    }
}
