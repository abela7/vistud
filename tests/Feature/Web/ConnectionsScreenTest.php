<?php

namespace Tests\Feature\Web;

use App\Audit\AuditAction;
use App\Identity\PrincipalFactory;
use App\Livewire\Study\Connections;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\ConnectsAiApps;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** Settings › AI › Connected AI apps (docs/specs/mcp-connections-plan.md M6.1). */
class ConnectionsScreenTest extends TestCase
{
    use ConnectsAiApps, CreatesAccounts, RefreshesDatabase;

    public function test_with_nothing_connected_it_says_how_to_connect(): void
    {
        $this->withoutVite();
        $this->actingAs($this->student())->get(route('settings', ['part' => 'ai']))
            ->assertOk()
            ->assertSee('Connected AI apps')
            ->assertSee('None connected')
            ->assertSee(url('/mcp'));
    }

    public function test_a_connected_app_shows_its_company_and_level_and_can_be_cut_off(): void
    {
        $ada = $this->student();
        $tokens = $this->connectApp($ada, 'read');

        Livewire::actingAs($ada)->test(Connections::class)
            ->assertSee('Anthropic (Claude)')
            ->assertSee('claude.ai')
            ->assertSee('Read only')
            ->call('disconnect', (string) DB::table('mcp_connections')->value('id'))
            ->assertSee('is disconnected')
            ->assertSee('None connected');

        $this->assertCount(1, $this->auditRows(AuditAction::CONNECTION_REVOKED));
        $this->callTool($tokens['access_token'], 'me')->assertStatus(401);
    }

    public function test_it_lists_what_the_apps_read_without_the_text(): void
    {
        $ada = $this->student();
        $tokens = $this->connectApp($ada);
        $this->callTool($tokens['access_token'], 'me')->assertOk();

        $this->app['auth']->shouldUse('web');
        Livewire::actingAs($ada)->test(Connections::class)
            ->assertSee('What they read lately (1)')
            ->assertSee('Anthropic (Claude) · me');
    }

    public function test_disconnect_all_asks_for_the_password_first(): void
    {
        $ada = $this->student();
        $this->connectApp($ada);

        Livewire::actingAs($ada)->test(Connections::class)
            ->call('disconnectAll')
            ->assertRedirect(route('password.confirm'));
        $this->assertSame(1, DB::table('mcp_connections')->whereNull('revoked_at')->count());

        // Livewire's test requests skip middleware, so nothing gives them the session (as AccountsScreenTest does).
        $this->actingAs($ada)->withSession($this->confirmedSession());
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));
        app(PrincipalFactory::class)->forget(request());
        Livewire::test(Connections::class)
            ->call('disconnectAll')
            ->assertSee('One app is disconnected.');
        $this->assertSame(0, DB::table('mcp_connections')->whereNull('revoked_at')->count());
    }

    public function test_another_students_connection_cannot_be_cut(): void
    {
        $ada = $this->student();
        $this->connectApp($ada);
        $id = (string) DB::table('mcp_connections')->value('id');

        Livewire::actingAs($this->student())->test(Connections::class)
            ->call('disconnect', $id)
            ->assertSee('That app is already gone.');
        $this->assertNull(DB::table('mcp_connections')->value('revoked_at'));
    }

    public function test_an_account_that_is_only_an_admin_sees_nothing_to_connect(): void
    {
        Livewire::actingAs($this->admin(student: false))->test(Connections::class)
            ->assertDontSee('Connected AI apps');
    }

    public function test_it_offers_the_install_packages_for_each_chat_app(): void
    {
        $this->withoutVite();
        $ada = $this->student();

        $page = $this->actingAs($ada)->get(route('settings', ['part' => 'ai']))->assertOk()->assertSee('How to connect an AI app')->assertSee('Download for Claude')
            ->assertSee('codex mcp add vistud --url', false)->assertSee(route('connections.plugin', 'gemini').'" download', false);
        $this->assertStringContainsString(route('connections.plugin', 'openai'), $page->getContent());

        $zip = $this->actingAs($ada)->get(route('connections.plugin', 'claude'))->assertOk();
        $this->assertSame('application/zip', $zip->headers->get('Content-Type'));
        $this->assertStringContainsString('vistud-claude.zip', (string) $zip->headers->get('Content-Disposition'));
        $this->get('/connections/plugin/nothing')->assertNotFound();
    }

    public function test_the_package_needs_a_signed_in_student(): void
    {
        $this->get(route('connections.plugin', 'claude'))->assertRedirect();
    }
}
