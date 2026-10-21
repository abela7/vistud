<?php

namespace App\Console\Commands;

use App\Identity\ErasureLedger;
use App\Identity\RestoreErasures;
use App\Platform\Access\Principal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ErasureLedgerCommand extends Command
{
    protected $signature = 'vistud:erasure:ledger {action : init, status, recover, sync, or export} {--output= : New independent export file} {--dry-run} {--offline-confirmed}';

    protected $description = 'Persist or export the independent content-free erasure ledger.';

    public function handle(ErasureLedger $ledger, RestoreErasures $restores): int
    {
        try {
            $action = $this->argument('action');
            if (! in_array($action, ['init', 'status', 'recover', 'sync', 'export'], true)) {
                throw new \RuntimeException;
            }
            if ($this->option('dry-run')) {
                $state = $ledger->read();
                $this->line('ledger_records='.$state['seq'].($action === 'sync' ? ' database_requests='.DB::table('erasure_requests')->count() : ''));

                return self::SUCCESS;
            }
            if ($action === 'init') {
                if (! $this->option('offline-confirmed') || ! app()->isDownForMaintenance()) {
                    throw new \RuntimeException;
                }
                $ledger->initialize();
            }
            if ($action === 'recover') {
                $ledger->recover();
            }
            if ($action === 'sync') {
                $restores->synchronize(Principal::system());
            }
            $state = $action === 'export' ? $ledger->export((string) $this->option('output')) : $ledger->read();
            $this->line('ledger_records='.$state['seq'].' head='.$state['hash']);

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Independent erasure ledger unavailable, invalid, or conflicting. Keep recovery offline; check configuration and the latest independent checkpoint.');

            return self::FAILURE;
        }
    }
}
