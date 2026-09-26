<?php

namespace Tests\Feature\Web;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Identity\Accounts;
use App\Identity\PrincipalFactory;
use App\Identity\Roles;
use App\Livewire\Admin\AuditLog\Index;
use App\Models\User;
use App\Platform\Access\Area;
use App\Platform\Access\Principal;
use App\Platform\Errors\Forbidden;
use Illuminate\Support\Facades\DB;
use Illuminate\View\ViewException;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The admin audit log page (WP6; ADR 0003 §10.4). T8's own tests belong to V2. */
class AuditLogScreenTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->admin = $this->admin(attributes: ['name' => 'Grace Hopper']);
    }

    public function test_entries_read_as_sentences_newest_first(): void
    {
        $ada = $this->student(['name' => 'Ada Lovelace']);
        app(Accounts::class)->suspend($this->principal($this->admin), $ada->id);
        app(Roles::class)->grantAdmin(Principal::system(), $ada->id);

        $this->actingAs($this->admin)->withSession($this->confirmedSession())
            ->get('/admin/audit-log')
            ->assertOk()
            ->assertSee('<title>Audit log · Admin', false)
            ->assertSee('aria-current="page"', false)
            ->assertSeeInOrder(['The server console', 'gave admin to', 'Ada Lovelace', 'Grace Hopper', 'suspended', 'Ada Lovelace']);
    }

    public function test_filtering_by_action_and_showing_more(): void
    {
        $by = $this->principal($this->admin);
        $students = collect(range(1, 3))->map(fn () => $this->student());
        foreach ($students as $student) {
            app(Accounts::class)->suspend($by, $student->id);
        }
        app(Accounts::class)->reactivate($by, $students[0]->id);

        $this->auditLog()
            ->assertSee('4 entries')
            ->set('action', AuditAction::ACCOUNT_REACTIVATED)
            ->assertSee('1 entry')
            ->set('action', 'not-an-action')
            ->assertSee('4 entries');

        foreach (range(1, 60) as $i) {
            app(AuditLog::class)->record(Principal::system(), AuditAction::ACCOUNT_CREATED);
        }
        $this->auditLog()
            ->assertSee('Showing the latest 50')
            ->call('showMore')
            ->assertSee('64 entries');
    }

    public function test_a_deleted_actor_is_named_plainly_and_only_account_actions_name_a_target(): void
    {
        DB::table('audit_log')->insert([
            'occurred_at' => now(), 'actor_type' => 'user', 'actor_user_id' => '01900000-0000-7000-8000-000000000000',
            'actor_role' => 'admin', 'action' => AuditAction::ADMIN_AREA_ENTERED, 'request_id' => 'req',
            'target_type' => 'user', 'target_id' => '01900000-0000-7000-8000-000000000000',
        ]);

        $this->auditLog()
            ->assertSeeInOrder(['A deleted account', 'entered the admin area'])
            ->assertDontSee('their own account');
    }

    public function test_the_component_refuses_a_non_admin(): void
    {
        try {
            $this->auditLog($this->student());
            $this->fail('A student rendered the audit log.');
        } catch (ViewException $e) {
            $this->assertInstanceOf(Forbidden::class, $e->getPrevious());
        }
    }

    private function auditLog(?User $as = null)
    {
        $this->actingAs($as ?? $this->admin)->withSession([
            PrincipalFactory::PASSWORD_CONFIRMED_KEY => now()->getTimestamp(),
            PrincipalFactory::AREA_KEY => Area::Admin->value,
        ]);
        // Livewire's test requests skip middleware, so nothing gives them the session.
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        return Livewire::test(Index::class);
    }
}
