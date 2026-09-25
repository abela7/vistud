<?php

namespace App\Platform\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Gives the runtime database user exactly the privileges it needs, table by
 * table (docs/development/setup.md):
 *
 * - SELECT and INSERT on append-only tables (audit_log, ADR 0003 §10.4);
 * - SELECT on read-only tables (migrations);
 * - SELECT, INSERT, UPDATE and DELETE on everything else.
 *
 * It never grants schema changes. It runs as the schema owner, after every
 * migration and on demand through `php artisan vistud:db:grants`.
 */
final class RuntimeGrants
{
    private const NAME = '/^[A-Za-z0-9_.%-]{1,64}$/';

    /** @return list<string> the statements executed, or an empty list when skipped */
    public function apply(?string $ownerConnection = null): array
    {
        $ownerConnection ??= config('vistud.database.owner_connection');
        $owner = DB::connection($ownerConnection);
        if ($owner->getDriverName() !== 'mysql') {
            return [];
        }

        $user = (string) config('vistud.database.runtime_user');
        $hosts = config('vistud.database.runtime_hosts') ?: ['%'];
        if ($user === '' || $user === $owner->getConfig('username')) {
            // A single-user setup cannot enforce append-only tables. The
            // audit-log permission test fails loudly in that case.
            return [];
        }

        foreach ([$user, ...$hosts] as $name) {
            if (preg_match(self::NAME, $name) !== 1) {
                throw new RuntimeException('Invalid runtime database user or host name in configuration.');
            }
        }

        $database = $owner->getDatabaseName();
        $appendOnly = config('vistud.database.append_only_tables', []);
        $readOnly = config('vistud.database.read_only_tables', []);
        $statements = [];

        foreach (Schema::connection($ownerConnection)->getTableListing($database, false) as $table) {
            if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $table) !== 1) {
                continue;
            }
            $privileges = match (true) {
                in_array($table, $appendOnly, true) => 'SELECT, INSERT',
                in_array($table, $readOnly, true) => 'SELECT',
                default => 'SELECT, INSERT, UPDATE, DELETE',
            };
            foreach ($hosts as $host) {
                $target = "`{$database}`.`{$table}`";
                $grantee = "'{$user}'@'{$host}'";
                $statements[] = "REVOKE IF EXISTS ALL PRIVILEGES ON {$target} FROM {$grantee} IGNORE UNKNOWN USER";
                $statements[] = "GRANT {$privileges} ON {$target} TO {$grantee}";
            }
        }

        foreach ($statements as $statement) {
            $owner->statement($statement);
        }

        return $statements;
    }
}
