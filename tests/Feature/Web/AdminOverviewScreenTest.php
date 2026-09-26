<?php

namespace Tests\Feature\Web;

use App\Identity\PrincipalFactory;
use App\Models\User;
use App\Platform\Access\Area;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The admin landing page and the switch between areas (WP6; ADR 0003 §10). */
class AdminOverviewScreenTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_an_admin_sees_the_overview_with_the_admin_marker(): void
    {
        $this->actingAs($this->admin())->withSession($this->confirmedSession())
            ->get('/admin')
            ->assertOk()
            ->assertSee('Admin overview')
            ->assertSee('class="admin-marker"', false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('action="'.route('area.switch', 'student').'"', false)
            ->assertSessionHas(PrincipalFactory::AREA_KEY, Area::Admin->value);
    }

    public function test_entering_the_admin_area_asks_for_the_password_first(): void
    {
        $this->actingAs($this->admin())->get('/admin')->assertRedirect(route('password.confirm'));
    }

    public function test_a_student_gets_forbidden_and_sees_no_admin_link(): void
    {
        $student = $this->student();

        $this->actingAs($student)->get('/admin')->assertForbidden();
        $this->actingAs($student)->get('/')->assertDontSee(route('admin.overview'));
    }

    public function test_home_links_admins_to_the_admin_area_from_the_account_menu(): void
    {
        $this->actingAs($this->admin())->get('/')
            ->assertSee('href="'.route('admin.overview').'" class="menu-item"', false)
            ->assertDontSee('class="admin-marker"', false);
    }

    public function test_an_admin_can_switch_back_to_the_student_area(): void
    {
        $this->actingAs($this->admin())->withSession($this->confirmedSession(Area::Admin))
            ->post('/area/student')
            ->assertRedirect('/')
            ->assertSessionHas(PrincipalFactory::AREA_KEY, Area::Student->value);
    }

    public function test_an_admin_without_a_student_role_gets_no_student_switch(): void
    {
        $admin = User::factory()->admin()->twoFactor()->create()->fresh();

        $this->actingAs($admin)->withSession($this->confirmedSession())->get('/admin')
            ->assertOk()
            ->assertDontSee('action="'.route('area.switch', 'student').'"', false);
    }
}
