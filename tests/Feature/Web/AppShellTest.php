<?php

namespace Tests\Feature\Web;

use App\Models\User;
use App\Platform\Access\Workspace;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The signed-in frame shared by both workspaces (ADR 0003 §10–11, DESIGN.md §7.2). */
class AppShellTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_the_student_frame_marks_the_current_page_and_offers_the_account_menu(): void
    {
        $student = $this->student(['name' => 'Ada Lovelace']);

        $this->actingAs($student)->get('/')
            ->assertOk()
            ->assertSee('href="#main"', false)
            ->assertSee('aria-controls="app-drawer"', false)
            ->assertSee('<dialog id="app-drawer"', false)
            ->assertSee('data-sidebar-toggle', false)
            ->assertSee('<a href="'.route('home').'" class="nav-item" title="Home"  aria-current="page"', false)
            ->assertSee('AL')
            ->assertSee('action="'.route('logout').'"', false)
            ->assertSee('href="'.route('two-factor.setup').'" class="menu-item"', false)
            ->assertDontSee('class="admin-marker"', false)
            ->assertDontSee('href="'.route('admin.overview').'"', false);
    }

    public function test_an_admin_in_the_student_area_can_open_the_admin_area_from_the_menu(): void
    {
        $this->actingAs($this->admin())->get('/')
            ->assertSee('href="'.route('admin.overview').'" class="menu-item"', false);
    }

    public function test_the_admin_frame_has_the_marker_its_own_items_and_a_switch_back(): void
    {
        $this->actingAs($this->admin())->withSession($this->confirmedSession())->get('/admin')
            ->assertOk()
            ->assertSee('class="admin-marker"', false)
            ->assertSee('title="Overview"  aria-current="page"', false)
            ->assertDontSee('title="Home"', false)
            ->assertSee('action="'.route('workspace.switch', 'student').'"', false);
    }

    public function test_an_admin_without_a_student_role_gets_no_switch_back(): void
    {
        $admin = User::factory()->admin()->twoFactor()->create()->fresh();

        $this->actingAs($admin)->withSession($this->confirmedSession())->get('/admin')
            ->assertOk()
            ->assertDontSee('action="'.route('workspace.switch', 'student').'"', false);
    }

    public function test_the_security_page_stays_in_the_workspace_it_was_opened_from(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->withSession($this->confirmedSession(Workspace::Admin))->get('/user/two-factor')
            ->assertOk()->assertSee('class="admin-marker"', false);
        $this->actingAs($admin)->withSession($this->confirmedSession(Workspace::Student))->get('/user/two-factor')
            ->assertOk()->assertSee('title="Security"  aria-current="page"', false);
    }
}
