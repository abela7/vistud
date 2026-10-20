<?php

namespace Tests\Feature\Platform;

use App\Study\NoteDoc;
use App\Study\Notes;
use App\Study\Workspaces;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** DDL commits implicitly in MySQL: this scratch-only drill cannot use an outer transaction. */
class ContentSchemaRollbackTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    protected $connectionsToTransact = [];

    public function createApplication()
    {
        $app = parent::createApplication();
        $app['config']->set('database.default', 'mysql_owner');

        return $app;
    }

    public function test_verified_offline_decryption_schema_rollback_and_redeployment_preserve_a_note(): void
    {
        $this->assertSame('vistud_test', DB::connection('mysql_owner')->getDatabaseName());
        $this->assertSame('vistud_keys_test', DB::connection('mysql_keys')->getDatabaseName());
        try {
            $by = $this->principal($this->student());
            $workspace = app(Workspaces::class)->create($by, ['name' => 'Synthetic rollback course']);
            $note = app(Notes::class)->create($by, 'workspace', $workspace->id, 'Rollback 中文');
            app(Notes::class)->append($by, $note->id, [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Kept through rollback.']]]]);
            $migration = require database_path('migrations/2026_10_21_100000_encrypt_core_learner_text.php');
            $this->assertThrows(fn () => $migration->down(), \RuntimeException::class);
            $this->assertTrue(Schema::hasColumn('notes', 'content_version'));
            $this->artisan('vistud:encrypt:backfill', ['--decrypt' => true])->assertSuccessful();
            $this->artisan('migrate:rollback', ['--database' => 'mysql_owner', '--step' => 1, '--force' => true])->assertSuccessful();
            $this->assertFalse(Schema::hasColumn('notes', 'content_version'));
            $this->assertSame('varchar', collect(Schema::getColumns('notes'))->firstWhere('name', 'title')['type_name']);
            $this->assertSame('Rollback 中文', DB::table('notes')->where('id', $note->id)->value('title'));
            $this->artisan('migrate', ['--database' => 'mysql_owner', '--force' => true])->assertSuccessful();
            $this->artisan('vistud:encrypt:backfill')->assertSuccessful();
            $this->artisan('vistud:encrypt:backfill', ['--verify' => true])->assertSuccessful();
            $opened = app(Notes::class)->open($by, $note->id);
            $this->assertSame('Rollback 中文', $opened->title);
            $this->assertSame('Kept through rollback.', NoteDoc::text($opened->doc));
        } finally {
            // Recreate only the explicitly checked synthetic schemas, so the
            // following tests inherit a fully migrated, empty database.
            $this->migrateDatabases();
        }
    }
}
