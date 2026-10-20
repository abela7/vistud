<?php

namespace App\Identity;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * A student's time zone (`learners.timezone`, an IANA name). Every day the
 * app works out is in it: what is due today, streaks, the calendar, the
 * cards' days and what the AI is told "today" is. It is set once when the
 * invitation is accepted, from the browser (AccountFactory); this is where
 * the student changes it afterwards, from Settings or PATCH /api/v1/me. A
 * student changes their own and no one else's (Guard::learner); the
 * `learners` row is theirs by its id.
 */
final class TimeZones
{
    public function __construct(private AuditLog $audit) {}

    /** The student's zone, or UTC when the row holds a name this server doesn't know. */
    public function current(Principal $by): string
    {
        $zone = DB::table('learners')->where('id', Guard::learner($by)->learnerId)->value('timezone');

        return is_string($zone) && in_array($zone, DateTimeZone::listIdentifiers(), true) ? $zone : 'UTC';
    }

    /**
     * Changes the acting student's zone. Anything that isn't an IANA name is
     * refused (ValidationException, 422 validation_failed on `timezone`) and
     * nothing changes. Written to the audit log like other account changes;
     * the name itself isn't (metadata holds IDs and codes only).
     */
    public function change(Principal $by, string $timezone): void
    {
        $scope = Guard::learner($by);
        Validator::make(
            ['timezone' => $timezone],
            ['timezone' => ['required', 'string', 'timezone:all']],
            ['timezone.required' => 'Choose a time zone.', 'timezone.timezone' => 'Choose a time zone from the list.'],
        )->validate();

        DB::transaction(function () use ($by, $scope, $timezone) {
            DB::table('learners')->where('id', $scope->learnerId)->update(['timezone' => $timezone, 'updated_at' => now()]);
            $this->audit->record($by, AuditAction::ACCOUNT_TIMEZONE_CHANGED, 'user', $by->userId);
        });
    }
}
