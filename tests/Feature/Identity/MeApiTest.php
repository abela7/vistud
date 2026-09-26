<?php

namespace Tests\Feature\Identity;

use App\Platform\Http\Middleware\AddAccountHeader;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

class MeApiTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    public function test_it_describes_the_current_account(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->getJson('/api/v1/me')
            ->assertOk()
            ->assertHeader(AddAccountHeader::HEADER, $admin->id)
            ->assertJsonPath('account.id', $admin->id)
            ->assertJsonPath('account.roles', ['admin', 'student'])
            ->assertJsonPath('account.two_factor_confirmed', true)
            ->assertJsonPath('learner.timezone', 'UTC')
            ->assertJsonPath('area', 'student');
    }

    public function test_an_admin_only_account_has_no_learner(): void
    {
        $this->actingAs($this->admin(student: false))->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('learner', null)
            ->assertJsonPath('area', null);
    }

    public function test_guests_get_401(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
    }
}
