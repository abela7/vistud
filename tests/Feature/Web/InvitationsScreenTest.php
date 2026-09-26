<?php

namespace Tests\Feature\Web;

use App\Audit\AuditAction;
use App\Identity\Invitations as InvitationService;
use App\Identity\PrincipalFactory;
use App\Livewire\Admin\Accounts\Invitations;
use App\Models\User;
use App\Platform\Access\Area;
use App\Platform\Errors\Forbidden;
use Illuminate\Support\Facades\DB;
use Illuminate\View\ViewException;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** Inviting people from the admin Accounts page (WP6; ADR 0003 D4, PM decision Q3). */
class InvitationsScreenTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->admin = $this->admin(attributes: ['name' => 'Grace Hopper']);
    }

    public function test_inviting_shows_a_working_link_once_and_never_keeps_the_token(): void
    {
        $page = $this->invitations()->set('email', ' New@Example.test ')->call('invite')->assertHasNoErrors();

        preg_match('~value="([^"]*/invitation#([^"]+))"~', $page->html(), $match);
        [, $link, $token] = $match;
        $this->assertSame(route('invitations.show').'#'.$token, html_entity_decode($link));
        $this->assertSame(hash('sha256', $token), DB::table('invitations')->where('email', 'new@example.test')->value('token_hash'));
        $page->assertSee(['Invitation for new@example.test', 'This is the only time the link is shown.', 'new@example.test']);

        // The token isn't in the component's state, so it never goes back to the server,
        // and the next render no longer shows it.
        $this->assertStringNotContainsString($token, json_encode($page->snapshot));
        $page->call('$refresh')->assertDontSee($token)->assertSee('The link is only shown once.');

        $this->assertCount(1, $this->auditRows(AuditAction::INVITATION_CREATED));
    }

    public function test_bad_or_taken_addresses_are_field_errors(): void
    {
        $this->student(['email' => 'taken@example.test']);

        $this->invitations()
            ->set('email', 'not-an-email')->call('invite')
            ->assertHasErrors('email')->assertSee('Enter an email address, like name@example.com.')
            ->set('email', 'taken@example.test')->call('invite')
            ->assertSee('Someone already has an account with this email address.')
            ->assertSet('issuedTo', null);

        $this->assertSame(0, DB::table('invitations')->count());
    }

    public function test_an_old_password_confirmation_sends_the_admin_to_confirm_it_and_back(): void
    {
        $this->invitations(confirmedAt: now()->subMinutes(11)->getTimestamp())
            ->set('email', 'new@example.test')
            ->call('invite')
            ->assertRedirect(route('password.confirm'));

        $this->assertSame(route('admin.accounts'), session('url.intended'));
        $this->assertSame(0, DB::table('invitations')->count());
    }

    public function test_pending_invitations_are_listed_and_can_be_cancelled_after_confirming(): void
    {
        $issued = app(InvitationService::class)->invite($this->principal($this->admin), 'waiting@example.test');

        $this->invitations()
            ->assertSee(['Waiting to be accepted (1)', 'waiting@example.test', 'by Grace Hopper'])
            ->call('askCancel', $issued->id)
            ->assertDispatched('invitation-cancel-open')
            ->assertSee('Cancel the invitation for waiting@example.test?')
            ->call('cancel')
            ->assertDispatched('invitation-cancel-close')
            ->assertSee('The invitation for waiting@example.test is cancelled. Its link no longer works.')
            ->assertDontSee('Waiting to be accepted');

        $this->assertNotNull(DB::table('invitations')->where('id', $issued->id)->value('revoked_at'));
        $this->assertCount(1, $this->auditRows(AuditAction::INVITATION_REVOKED));
    }

    public function test_the_component_refuses_a_non_admin(): void
    {
        try {
            $this->invitations($this->student());
            $this->fail('A student rendered the Invitations component.');
        } catch (ViewException $e) {
            $this->assertInstanceOf(Forbidden::class, $e->getPrevious());
        }
    }

    public function test_the_accounts_page_has_both_parts(): void
    {
        $this->actingAs($this->admin)->withSession($this->confirmedSession())
            ->get('/admin/accounts')
            ->assertOk()
            ->assertSeeInOrder(['<h1', 'Accounts', 'Invite someone', 'Search accounts'], false);
    }

    private function invitations(?User $as = null, ?int $confirmedAt = null)
    {
        $this->actingAs($as ?? $this->admin)->withSession([
            PrincipalFactory::PASSWORD_CONFIRMED_KEY => $confirmedAt ?? now()->getTimestamp(),
            PrincipalFactory::AREA_KEY => Area::Admin->value,
        ]);
        // Livewire's test requests skip middleware, so nothing gives them the session.
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        return Livewire::test(Invitations::class);
    }
}
