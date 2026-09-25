<?php

namespace Tests\Feature\Identity;

use App\Identity\Accounts;
use App\Platform\Errors\Conflict;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * ADR 0003 §10.3, last-admin safeguard: two admins suspending each other at
 * the same moment must not both succeed. Runs with committed data and two
 * database connections, so it can't use the usual per-test transaction.
 */
class LastAdminConcurrencyTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    /** No wrapping transaction: the second connection must see committed rows. */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        // The runtime user can't delete audit records, by design; the owner can.
        $owner = DB::connection(config('vistud.database.owner_connection'));
        foreach (['audit_log', 'sessions', 'learners', 'user_roles', 'users'] as $table) {
            $owner->table($table)->delete();
        }

        parent::tearDown();
    }

    public function test_the_second_of_two_concurrent_suspensions_waits_and_is_then_refused(): void
    {
        $first = $this->admin();
        $second = $this->admin();
        $accounts = app(Accounts::class);
        $asFirst = $this->principal($first);
        $asSecond = $this->principal($second);

        config(['database.connections.mysql_other' => config('database.connections.mysql')]);
        DB::connection('mysql_other')->statement('SET SESSION innodb_lock_wait_timeout = 1');

        // Request 1: the first admin suspends the second, and hasn't committed yet.
        DB::beginTransaction();
        $accounts->suspend($asFirst, $second->id);

        // Request 2, on another connection: the second admin tries to suspend
        // the first. It must wait for request 1's locks rather than read stale rows.
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('mysql_other');
        try {
            $accounts->suspend($asSecond, $first->id);
            $this->fail('The second suspension did not wait for the first.');
        } catch (QueryException $e) {
            $this->assertSame(1205, $e->errorInfo[1] ?? null, 'Expected a lock wait timeout.');
        } finally {
            DB::setDefaultConnection($default);
        }

        DB::commit();

        // Request 2 retried after request 1 committed: the first admin is now the last.
        DB::setDefaultConnection('mysql_other');
        try {
            $this->assertThrows(fn () => $accounts->suspend($asSecond, $first->id), Conflict::class);
        } finally {
            DB::setDefaultConnection($default);
        }

        $this->assertSame('active', $first->fresh()->status);
        $this->assertSame('suspended', $second->fresh()->status);
    }
}
