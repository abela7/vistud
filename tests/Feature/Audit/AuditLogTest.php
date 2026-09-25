<?php

namespace Tests\Feature\Audit;

use App\Audit\AuditLog;
use App\Platform\Access\Principal;
use App\Platform\Errors\Forbidden;
use InvalidArgumentException;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    public function test_metadata_may_hold_ids_codes_and_flags_but_never_free_text(): void
    {
        $log = app(AuditLog::class);
        $log->record(Principal::system(), 'test.ok', metadata: ['role' => 'admin', 'count' => 2, 'self' => true, 'ids' => ['a-1', 'b-2']]);

        $this->assertThrows(fn () => $log->record(Principal::system(), 'test.email', metadata: ['email' => 'someone@example.test']), InvalidArgumentException::class);
        $this->assertThrows(fn () => $log->record(Principal::system(), 'test.text', metadata: ['note' => 'I was confused about joins']), InvalidArgumentException::class);
    }

    public function test_listing_is_for_admins_newest_first_with_a_cursor(): void
    {
        $log = app(AuditLog::class);
        foreach (['test.one', 'test.two', 'test.three'] as $action) {
            $log->record(Principal::system(), $action);
        }
        $admin = $this->admin();

        $page = $log->list($this->principal($admin, confirmed: false), ['action' => 'test.three']);
        $this->assertSame(['test.three'], array_column($page['data'], 'action'));

        $first = $log->list($this->principal($admin), limit: 2);
        $this->assertCount(2, $first['data']);
        $this->assertNotNull($first['next_cursor']);

        $this->assertThrows(fn () => $log->list($this->principal($this->student())), Forbidden::class);
    }
}
