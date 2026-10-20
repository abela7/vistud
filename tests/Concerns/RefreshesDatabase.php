<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Use this instead of RefreshDatabase in every test that touches MySQL.
 *
 * Tests run as the restricted runtime user, like the application. The schema
 * is created by the owner connection, which then grants the runtime user its
 * per-table privileges (docs/development/setup.md).
 */
trait RefreshesDatabase
{
    use RefreshDatabase {
        connectionsToTransact as protected contentConnectionsToTransact;
        migrateDatabases as protected migrateContentDatabases;
    }

    protected function connectionsToTransact()
    {
        $connections = $this->contentConnectionsToTransact();

        return $connections === [] ? [] : array_values(array_unique([...$connections, 'mysql_keys']));
    }

    protected function migrateDatabases()
    {
        // Only named test databases may ever be wiped by this trait.
        if (config('database.connections.mysql.database') !== 'vistud_test'
            || config('database.connections.mysql_keys.database') !== 'vistud_keys_test') {
            throw new \RuntimeException('Refusing to refresh a non-test database.');
        }
        $this->migrateContentDatabases();
        $this->artisan('migrate:fresh', [
            '--database' => 'mysql_keys_owner', '--path' => 'database/key-migrations', '--force' => true,
        ])->assertSuccessful();
    }

    protected function migrateFreshUsing()
    {
        return [
            '--database' => config('vistud.database.owner_connection'),
            '--drop-views' => $this->shouldDropViews(),
            '--drop-types' => $this->shouldDropTypes(),
            '--seed' => $this->shouldSeed(),
        ];
    }
}
