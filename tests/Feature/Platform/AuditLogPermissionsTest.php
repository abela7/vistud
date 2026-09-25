<?php

namespace Tests\Feature\Platform;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * M1 checklist: "The audit log is append-only at the database-permission
 * level" (ADR 0003 §14). The application's database user can insert and
 * read audit records, and nothing else.
 */
class AuditLogPermissionsTest extends TestCase
{
    use RefreshesDatabase;

    public function test_the_runtime_user_is_not_the_schema_owner(): void
    {
        $this->assertNotSame(
            config('database.connections.mysql_owner.username'),
            DB::connection()->getConfig('username'),
            'Tests must run as the restricted runtime user. See docs/development/setup.md.',
        );
    }

    public function test_the_runtime_user_can_insert_and_read_audit_records(): void
    {
        $id = DB::table('audit_log')->insertGetId($this->record());

        $this->assertSame('test.inserted', DB::table('audit_log')->where('id', $id)->value('action'));
    }

    public function test_the_runtime_user_cannot_update_audit_records(): void
    {
        $id = DB::table('audit_log')->insertGetId($this->record());

        $this->assertDenied(fn () => DB::table('audit_log')->where('id', $id)->update(['action' => 'test.changed']));
        $this->assertSame('test.inserted', DB::table('audit_log')->where('id', $id)->value('action'));
    }

    public function test_the_runtime_user_cannot_delete_audit_records(): void
    {
        $id = DB::table('audit_log')->insertGetId($this->record());

        $this->assertDenied(fn () => DB::table('audit_log')->where('id', $id)->delete());
        $this->assertDenied(fn () => DB::statement('TRUNCATE TABLE audit_log'));
        $this->assertSame(1, DB::table('audit_log')->where('id', $id)->count());
    }

    public function test_the_runtime_user_cannot_change_the_schema(): void
    {
        $this->assertDenied(fn () => DB::statement('ALTER TABLE audit_log ADD COLUMN sneaky INT'));
        $this->assertDenied(fn () => DB::statement('DROP TABLE audit_log'));
    }

    private function assertDenied(callable $statement): void
    {
        try {
            $statement();
        } catch (QueryException $e) {
            // 1142: command denied to user for table.
            $this->assertSame(1142, $e->errorInfo[1] ?? null, $e->getMessage());

            return;
        }
        $this->fail('The runtime database user was allowed to run the statement.');
    }

    private function record(): array
    {
        return [
            'occurred_at' => now(),
            'actor_type' => 'system',
            'actor_role' => 'system',
            'action' => 'test.inserted',
        ];
    }
}
