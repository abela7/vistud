<?php

namespace Tests\Feature\Identity;

use App\Audit\AuditAction;
use App\Identity\TimeZones;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Platform\Errors\Forbidden;
use App\Study\Sessions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** A student changes their own time zone after sign-up (learners.timezone), and no one else's. */
class TimeZonesTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private TimeZones $zones;

    protected function setUp(): void
    {
        parent::setUp();
        $this->zones = app(TimeZones::class);
    }

    public function test_a_student_changes_their_zone_every_day_follows_it_and_it_is_audited(): void
    {
        $ada = $this->student();
        $by = $this->principal($ada);
        $this->assertSame('UTC', $this->zones->current($by));

        $this->zones->change($by, 'Europe/London');

        $this->assertSame('Europe/London', $this->zones->current($by));
        $this->assertSame('Europe/London', DB::table('learners')->where('user_id', $ada->id)->value('timezone'));
        // What the Study services call the student's day follows at once.
        $this->assertSame('Europe/London', app(Sessions::class)->timezone($by));

        [$audit] = $this->auditRows(AuditAction::ACCOUNT_TIMEZONE_CHANGED);
        $this->assertSame(['user', $ada->id, 'student', 'user', $ada->id, null], [$audit->actor_type, $audit->actor_user_id, $audit->actor_role, $audit->target_type, $audit->target_id, $audit->metadata]);
    }

    public function test_a_name_that_is_not_a_zone_is_refused_and_nothing_changes(): void
    {
        $ada = $this->student();
        $by = $this->principal($ada);

        foreach (['Mars/Olympus', '', 'europe/london', '+01:00', 'GMT+1'] as $notAZone) {
            $this->assertThrows(fn () => $this->zones->change($by, $notAZone), ValidationException::class);
        }

        $this->assertSame('UTC', DB::table('learners')->where('user_id', $ada->id)->value('timezone'));
        $this->assertSame([], $this->auditRows(AuditAction::ACCOUNT_TIMEZONE_CHANGED));
    }

    public function test_another_students_zone_is_untouched(): void
    {
        $ada = $this->student();
        $bob = User::factory()->student('Asia/Tokyo')->create()->fresh();

        $this->zones->change($this->principal($ada), 'America/New_York');

        $this->assertSame('America/New_York', DB::table('learners')->where('user_id', $ada->id)->value('timezone'));
        $this->assertSame('Asia/Tokyo', DB::table('learners')->where('user_id', $bob->id)->value('timezone'));
        $this->assertSame('Asia/Tokyo', $this->zones->current($this->principal($bob)));
    }

    public function test_only_a_student_has_a_zone_of_their_own(): void
    {
        $ops = $this->principal($this->admin(student: false));

        $this->assertThrows(fn () => $this->zones->change($ops, 'Europe/London'), Forbidden::class);
        $this->assertThrows(fn () => $this->zones->current($ops), Forbidden::class);
        $this->assertThrows(fn () => $this->zones->change(Principal::system(), 'Europe/London'), Forbidden::class);
    }
}
