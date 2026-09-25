<?php

namespace App\Console\Commands;

use App\Platform\Database\RuntimeGrants;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('vistud:db:grants')]
#[Description('Give the runtime database user its per-table privileges (runs automatically after migrations)')]
class GrantRuntimePrivileges extends Command
{
    public function handle(RuntimeGrants $grants): int
    {
        $statements = $grants->apply();
        if ($statements === []) {
            $this->warn('Skipped: no separate runtime user is configured, or the database is not MySQL.');

            return self::SUCCESS;
        }

        $this->info('Applied '.intdiv(count($statements), 2).' table grants for the runtime user.');

        return self::SUCCESS;
    }
}
