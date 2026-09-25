<?php

namespace Tests\Feature\Identity;

use App\Audit\AuditAction;
use App\Models\User;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** Fortify as configured for ViStud (ADR 0003 §10.3). */
class FortifyTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    public function test_an_active_account_can_log_in(): void
    {
        $user = $this->student(['email' => 'ada@example.test']);

        $this->postJson('/login', ['email' => 'ADA@example.test', 'password' => 'password-for-tests'])->assertOk();

        $this->assertAuthenticatedAs($user);
    }

    public function test_suspended_and_deleted_accounts_cannot_log_in_and_get_the_usual_answer(): void
    {
        User::factory()->student()->suspended()->create(['email' => 'suspended@example.test']);
        User::factory()->student()->deleted()->create(['email' => 'deleted@example.test']);

        foreach (['suspended@example.test', 'deleted@example.test', 'nobody@example.test'] as $email) {
            $this->postJson('/login', ['email' => $email, 'password' => 'password-for-tests'])
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'validation_failed');
        }
        $this->assertGuest();
    }

    public function test_registration_is_off(): void
    {
        $this->postJson('/register', ['name' => 'X', 'email' => 'x@example.test', 'password' => 'a-long-enough-password'])
            ->assertNotFound();
    }

    public function test_recovery_codes_are_shown_once_after_they_are_generated(): void
    {
        $user = $this->admin();
        $session = $this->confirmedSession();

        // Nothing generated in this session: no showing.
        $this->actingAs($user)->withSession($session)->getJson('/user/two-factor-recovery-codes')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'recovery_codes_already_shown');

        $this->actingAs($user)->withSession($session)->postJson('/user/two-factor-recovery-codes')->assertOk();
        $codes = $this->actingAs($user)->getJson('/user/two-factor-recovery-codes')->assertOk()->json();
        $this->assertCount(8, $codes);

        $this->actingAs($user)->getJson('/user/two-factor-recovery-codes')->assertForbidden();
        $this->assertCount(1, $this->auditRows(AuditAction::RECOVERY_CODES_GENERATED));
    }

    public function test_codes_from_enabling_2fa_are_shown_once(): void
    {
        $user = User::factory()->student()->admin()->create()->fresh();
        $session = $this->confirmedSession();

        $this->actingAs($user)->withSession($session)->postJson('/user/two-factor-authentication')->assertOk();
        $this->actingAs($user)->getJson('/user/two-factor-recovery-codes')->assertOk();
        $this->actingAs($user)->getJson('/user/two-factor-recovery-codes')->assertForbidden();
    }

    public function test_2fa_management_needs_a_confirmation_from_the_last_10_minutes_and_answers_with_the_envelope(): void
    {
        $user = $this->admin();

        $this->travelTo(now());
        $session = $this->confirmedSession();
        $this->travel(601)->seconds();

        $this->actingAs($user)->withSession($session)->postJson('/user/two-factor-recovery-codes')
            ->assertStatus(423)
            ->assertJsonPath('error.code', 'password_confirmation_required');
    }
}
