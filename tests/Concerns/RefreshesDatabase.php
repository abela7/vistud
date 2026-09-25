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
    use RefreshDatabase;

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
