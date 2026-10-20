<?php

namespace Tests\Feature\Identity;

use App\Audit\AuditAction;
use App\Platform\Http\Middleware\AddAccountHeader;
use Illuminate\Support\Facades\DB;
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
        $this->patchJson('/api/v1/me', ['timezone' => 'Europe/London'])->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_a_student_changes_their_time_zone(): void
    {
        $ada = $this->student();

        $this->actingAs($ada)->patchJson('/api/v1/me', ['timezone' => 'Europe/London'])
            ->assertOk()
            ->assertJsonPath('account.id', $ada->id)
            ->assertJsonPath('learner.timezone', 'Europe/London')
            ->assertJsonPath('area', 'student');

        $this->assertSame('Europe/London', DB::table('learners')->where('user_id', $ada->id)->value('timezone'));
        $this->assertCount(1, $this->auditRows(AuditAction::ACCOUNT_TIMEZONE_CHANGED));
    }

    public function test_a_name_that_is_not_a_time_zone_is_refused(): void
    {
        $ada = $this->student();

        $this->actingAs($ada)->patchJson('/api/v1/me', ['timezone' => 'Mars/Olympus'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.details.fields.timezone.0', 'Choose a time zone from the list.');
        $this->actingAs($ada)->patchJson('/api/v1/me', [])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['timezone']]]]);

        $this->assertSame('UTC', DB::table('learners')->where('user_id', $ada->id)->value('timezone'));
        $this->assertSame([], $this->auditRows(AuditAction::ACCOUNT_TIMEZONE_CHANGED));
    }

    public function test_an_account_without_a_learner_has_no_time_zone_to_change(): void
    {
        $this->actingAs($this->admin(student: false))->patchJson('/api/v1/me', ['timezone' => 'Europe/London'])
            ->assertForbidden()->assertJsonPath('error.code', 'student_role_required');
    }
}
