<?php

namespace Tests\Unit\Platform;

use App\Platform\Access\Guard;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Access\Role;
use App\Platform\Errors\Forbidden;
use App\Platform\Errors\PasswordConfirmationRequired;
use App\Platform\Errors\TwoFactorRequired;
use DateTimeImmutable;
use Tests\TestCase;

class GuardTest extends TestCase
{
    public function test_a_student_is_not_an_admin(): void
    {
        $this->expectException(Forbidden::class);
        Guard::admin($this->principal([Role::Student]));
    }

    public function test_an_admin_needs_confirmed_two_factor(): void
    {
        $this->expectException(TwoFactorRequired::class);
        Guard::admin($this->principal([Role::Admin], twoFactor: false));
    }

    public function test_protected_actions_need_a_recent_password_confirmation(): void
    {
        $this->travelTo(new DateTimeImmutable('2026-10-01 12:00:00'));

        Guard::protectedAdmin($this->principal([Role::Admin], confirmedAt: '2026-10-01 11:50:00'));

        $this->expectException(PasswordConfirmationRequired::class);
        Guard::protectedAdmin($this->principal([Role::Admin], confirmedAt: '2026-10-01 11:49:59'));
    }

    public function test_protected_actions_without_any_confirmation_are_refused(): void
    {
        $this->expectException(PasswordConfirmationRequired::class);
        Guard::protectedAdmin($this->principal([Role::Admin]));
    }

    public function test_the_system_principal_passes_only_where_a_service_opts_in(): void
    {
        Guard::protectedAdmin(Principal::system(), allowSystem: true);

        $this->expectException(Forbidden::class);
        Guard::protectedAdmin(Principal::system());
    }

    public function test_a_learner_scope_needs_the_student_role_and_admin_adds_nothing(): void
    {
        $this->assertSame('L-1', Guard::learner($this->principal([Role::Student, Role::Admin]))->learnerId);

        $this->expectException(Forbidden::class);
        LearnerScope::of($this->principal([Role::Admin]));
    }

    public function test_the_system_principal_has_no_learner_scope(): void
    {
        $this->expectException(Forbidden::class);
        LearnerScope::of(Principal::system());
    }

    private function principal(array $roles, bool $twoFactor = true, ?string $confirmedAt = null): Principal
    {
        return new Principal(
            userId: 'U-1',
            learnerId: in_array(Role::Student, $roles, true) ? 'L-1' : null,
            roles: $roles,
            twoFactorConfirmed: $twoFactor,
            passwordConfirmedAt: $confirmedAt === null ? null : new DateTimeImmutable($confirmedAt),
            channel: 'web',
        );
    }
}
