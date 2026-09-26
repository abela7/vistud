<?php

namespace Tests\Feature\Web;

use App\Audit\AuditAction;
use App\Identity\PrincipalFactory;
use App\Livewire\Admin\Accounts\Index;
use App\Models\User;
use App\Platform\Access\Role;
use App\Platform\Access\Workspace;
use App\Platform\Errors\Forbidden;
use Illuminate\Support\Facades\DB;
use Illuminate\View\ViewException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The admin Accounts page (WP6; ADR 0003 §10.1–10.3). The services' own rules are tested in tests/Feature/Identity. */
class AccountsScreenTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->admin = $this->admin(attributes: ['name' => 'Grace Hopper']);
    }

    public function test_the_page_lists_accounts_with_their_roles_and_state(): void
    {
        $this->student(['name' => 'Ada Lovelace', 'email' => 'ada@example.test']);
        $suspended = $this->student(['name' => 'Alan Turing']);
        $suspended->forceFill(['status' => 'suspended'])->save();

        $this->actingAs($this->admin)->withSession($this->confirmedSession())
            ->get('/admin/accounts')
            ->assertOk()
            ->assertSee('<title>Accounts · Admin', false)
            ->assertSeeInOrder(['Grace Hopper', 'You', 'Admin', 'Ada Lovelace', 'ada@example.test', 'Student', 'Alan Turing', 'Suspended'])
            ->assertSee('3 accounts');
    }

    public function test_a_student_gets_forbidden(): void
    {
        $this->actingAs($this->student())->get('/admin/accounts')->assertForbidden();
    }

    public function test_the_component_refuses_a_non_admin(): void
    {
        try {
            $this->accountsPage($this->student());
            $this->fail('A student rendered the Accounts component.');
        } catch (ViewException $e) {
            $this->assertInstanceOf(Forbidden::class, $e->getPrevious());
        }
    }

    public function test_a_student_replaying_the_pages_livewire_request_gets_forbidden(): void
    {
        $ada = $this->student();
        $page = $this->actingAs($this->admin)->withSession($this->confirmedSession())->get('/admin/accounts')->getContent();
        preg_match('/wire:snapshot="([^"]+)"/', $page, $match);
        $request = ['components' => [[
            'snapshot' => html_entity_decode($match[1]),
            'updates' => [],
            'calls' => [['method' => 'open', 'params' => [$ada->id], 'metadata' => []]],
        ]]];
        $uri = app(HandleRequests::class)->getUpdateUri();

        $this->actingAs($this->admin)->withSession($this->confirmedSession(Workspace::Admin))
            ->postJson($uri, $request, ['X-Livewire' => '1'])->assertOk();

        // Livewire resets its per-request state when a real request ends; a test has to do it.
        Livewire::flushState();
        $this->actingAs($this->student())->postJson($uri, $request, ['X-Livewire' => '1'])->assertForbidden();
        // The page's own role check ran again (it audits the refusal), not only the service's.
        $this->assertCount(1, $this->auditRows(AuditAction::ADMIN_ACCESS_DENIED));
    }

    public function test_searching_narrows_the_list(): void
    {
        $this->student(['name' => 'Ada Lovelace']);
        $this->student(['name' => 'Alan Turing']);

        $this->accountsPage()
            ->set('search', 'lovelace')
            ->assertSee(['Ada Lovelace', '1 match'])
            ->assertDontSee('Alan Turing')
            ->set('search', 'nobody')
            ->assertSee('No accounts match “nobody”.');
    }

    public function test_suspending_an_account_asks_first_then_calls_the_service(): void
    {
        $ada = $this->student(['name' => 'Ada Lovelace']);

        $this->accountsPage()
            ->call('open', $ada->id)
            ->assertSet('selectedId', $ada->id)
            ->assertDispatched('account-dialog-open')
            ->assertSee(['Suspend account', 'Make admin'])
            ->call('ask', 'suspend')
            ->assertSee('Suspend Ada Lovelace?')
            ->call('confirm')
            ->assertSet('selectedId', null)
            ->assertDispatched('account-dialog-close')
            ->assertSee('Ada Lovelace is suspended and has been logged out everywhere.');

        $this->assertSame('suspended', $ada->fresh()->status);
        $this->assertCount(1, $this->auditRows(AuditAction::ACCOUNT_SUSPENDED));
    }

    public function test_reactivating_granting_revoking_and_resetting_two_factor(): void
    {
        $ada = $this->student(['name' => 'Ada Lovelace']);
        $ada->forceFill(['status' => 'suspended'])->save();
        $other = $this->admin(attributes: ['name' => 'Alan Turing']);

        $this->accountsPage()
            ->call('open', $ada->id)->call('ask', 'reactivate')->call('confirm')
            ->assertSee('Ada Lovelace can log in again.')
            ->call('open', $ada->id)->call('ask', 'grant-admin')->call('confirm')
            ->assertSee('Ada Lovelace is now an admin.')
            ->call('open', $other->id)->call('ask', 'revoke-admin')->call('confirm')
            ->assertSee('Alan Turing is no longer an admin.')
            ->call('open', $other->id)->call('ask', 'reset-two-factor')->call('confirm')
            ->assertSee('Alan Turing’s two-factor authentication is reset.');

        $this->assertSame('active', $ada->fresh()->status);
        $this->assertTrue(DB::table('user_roles')->where('user_id', $ada->id)->where('role', Role::Admin->value)->exists());
        $this->assertFalse(DB::table('user_roles')->where('user_id', $other->id)->where('role', Role::Admin->value)->exists());
        $this->assertNull($other->fresh()->two_factor_confirmed_at);
        foreach ([AuditAction::ACCOUNT_REACTIVATED, AuditAction::ROLE_GRANTED, AuditAction::ROLE_REVOKED, AuditAction::TWO_FACTOR_RESET] as $action) {
            $this->assertCount(1, $this->auditRows($action), $action);
        }
    }

    public function test_the_last_admin_rule_is_shown_as_a_message(): void
    {
        // The page offers no actions on your own account; this forces one.
        $this->accountsPage()
            ->call('open', $this->admin->id)
            ->assertDontSee('Remove admin')
            ->call('ask', 'revoke-admin')
            ->call('confirm')
            ->assertSee('This would leave no active admin.')
            ->assertSet('selectedId', $this->admin->id);

        $this->assertTrue(DB::table('user_roles')->where('user_id', $this->admin->id)->where('role', Role::Admin->value)->exists());
    }

    public function test_an_old_password_confirmation_sends_the_admin_to_confirm_it_and_back(): void
    {
        $ada = $this->student();

        $this->accountsPage(confirmedAt: now()->subMinutes(11)->getTimestamp())
            ->call('open', $ada->id)
            ->call('ask', 'suspend')
            ->call('confirm')
            ->assertRedirect(route('password.confirm'));

        $this->assertSame(route('admin.accounts'), session('url.intended'));
        $this->assertSame('active', $ada->fresh()->status);
    }

    public function test_the_browser_cannot_change_which_account_an_action_targets(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        $this->accountsPage()->set('selectedId', $this->student()->id);
    }

    public function test_unknown_actions_are_ignored(): void
    {
        $this->accountsPage()
            ->call('open', $this->student()->id)
            ->call('ask', 'delete-everything')
            ->assertSet('pendingAction', null);
    }

    private function accountsPage(?User $as = null, ?int $confirmedAt = null)
    {
        $this->actingAs($as ?? $this->admin)->withSession([
            PrincipalFactory::PASSWORD_CONFIRMED_KEY => $confirmedAt ?? now()->getTimestamp(),
            PrincipalFactory::WORKSPACE_KEY => Workspace::Admin->value,
        ]);
        // Livewire's test requests skip middleware, so nothing gives them the session.
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        return Livewire::test(Index::class);
    }
}
