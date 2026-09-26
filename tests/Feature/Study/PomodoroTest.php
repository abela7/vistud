<?php

namespace Tests\Feature\Study;

use App\Brain\Store\JournalReader;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\Unprocessable;
use App\Study\SessionDetails;
use App\Study\Sessions;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Tests\Concerns\BuildsJournalEntries;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The Pomodoro clock of a study session (docs/specs/study-memory.md §4.2). */
class PomodoroTest extends TestCase
{
    use BuildsJournalEntries, CreatesAccounts, RefreshesDatabase;

    private const CLASSIC = ['focus' => 25, 'short' => 5, 'long' => 15, 'every' => 4, 'auto' => true];

    private Sessions $sessions;

    private User $ada;

    private Principal $by;

    private WorkspaceDetails $databases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
        $this->sessions = app(Sessions::class);
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->databases = app(Workspaces::class)->create($this->by, ['name' => 'Databases']);
    }

    public function test_a_focus_period_becomes_a_pomodoro_and_its_break_starts_and_ends_at_the_exact_moment(): void
    {
        $session = $this->sessions->start($this->by, $this->databases->id, pomodoro: self::CLASSIC);
        $this->assertSame(['focus', 'Focus 1 of 4', 1500, '25:00'], [$session->phase, $session->phaseWords(), $session->phaseRemaining(), SessionDetails::countdown($session->phaseRemaining())]);

        $this->active(20);
        $this->assertSame(300, $this->now()->phaseRemaining());
        // Two minutes into the break: it started when the 25 minutes were up.
        $this->active(7);
        $break = $this->now();
        $this->assertSame(['break', 'short_break', 1, 1500, 120, 180], [$break->state, $break->phase, $break->pomodoros, $break->studySeconds, $break->breakSeconds, $break->phaseRemaining()]);

        // The break runs out, and the next focus period starts by itself.
        $this->active(4);
        $focus = $this->now();
        $this->assertSame(['running', 'focus', 'Focus 2 of 4', 1560, 300], [$focus->state, $focus->phase, $focus->phaseWords(), $focus->studySeconds, $focus->breakSeconds]);
        $this->assertSame([['study', 'pomodoro'], ['break', 'pomodoro'], ['study', null]], array_map(fn ($s) => [$s->kind, $s->endedBy], $this->sessions->find($this->by, $session->id)->segments));
    }

    public function test_every_fourth_pomodoro_earns_the_long_break(): void
    {
        $this->sessions->start($this->by, $this->databases->id, pomodoro: ['focus' => 5, 'short' => 1, 'long' => 10, 'every' => 2, 'auto' => true]);
        $this->active(5);
        $this->assertSame(['short_break', 1], [$this->now()->phase, $this->now()->pomodoros]);
        $this->active(6);
        $this->assertSame(['long_break', 2, 'Long break'], [$this->now()->phase, $this->now()->pomodoros, $this->now()->phaseWords()]);
        $this->active(10);
        $this->assertSame(['focus', 'Focus 1 of 2'], [$this->now()->phase, $this->now()->phaseWords()]);
    }

    public function test_without_auto_start_the_next_focus_waits_for_the_student(): void
    {
        $session = $this->sessions->start($this->by, $this->databases->id, pomodoro: ['auto' => false] + self::CLASSIC);
        $this->active(25);
        $this->active(10);
        $waiting = $this->now();
        $this->assertSame(['paused', 'pomodoro', 'Ready for pomodoro 2', 1500, 300], [$waiting->state, $waiting->pausedBy, $waiting->phaseWords(), $waiting->studySeconds, $waiting->breakSeconds]);

        $this->sessions->resume($this->by, $session->id);
        $this->active(1);
        $this->assertSame(['running', 'focus', 1440], [$this->now()->state, $this->now()->phase, $this->now()->phaseRemaining()]);
    }

    public function test_a_pause_stops_the_countdown_and_a_pause_in_a_break_resumes_the_break(): void
    {
        $session = $this->sessions->start($this->by, $this->databases->id, pomodoro: self::CLASSIC);
        $this->active(10);
        $this->sessions->pause($this->by, $session->id);
        $this->travel(20)->minutes();
        $this->assertSame(900, $this->now()->phaseRemaining());
        $this->sessions->resume($this->by, $session->id);
        $this->active(16);
        $this->assertSame(['break', 1, 1500], [$this->now()->state, $this->now()->pomodoros, $this->now()->studySeconds]);

        $this->sessions->pause($this->by, $session->id);
        $this->travel(10)->minutes();
        $this->sessions->resume($this->by, $session->id);
        $this->assertSame(['break', 'short_break', 240], [$this->now()->state, $this->now()->phase, $this->now()->phaseRemaining()]);
    }

    public function test_skipping_ends_a_focus_period_early_without_counting_it_and_ends_a_break(): void
    {
        $session = $this->sessions->start($this->by, $this->databases->id, pomodoro: self::CLASSIC);
        $this->active(10);
        $this->sessions->skip($this->by, $session->id);
        $this->assertSame(['break', 'short_break', 0, 1], [$this->now()->state, $this->now()->phase, $this->now()->pomodoros, $this->now()->pomodorosSkipped]);

        $this->active(1);
        $this->sessions->skip($this->by, $session->id);
        $this->assertSame(['running', 'focus', 1500], [$this->now()->state, $this->now()->phase, $this->now()->phaseRemaining()]);
    }

    public function test_a_focus_period_doesnt_end_while_the_student_is_away(): void
    {
        $this->sessions->start($this->by, $this->databases->id, pomodoro: ['auto' => false] + self::CLASSIC);
        $this->active(2);
        $this->travel(40)->minutes();
        $away = $this->now();
        $this->assertSame(['paused', 'away', 0, 120], [$away->state, $away->pausedBy, $away->pomodoros, $away->studySeconds]);
    }

    public function test_the_pomodoro_clock_is_turned_on_and_off_during_a_session_and_counted_in_the_totals(): void
    {
        $session = $this->sessions->start($this->by, $this->databases->id);
        $this->assertFalse($session->usesPomodoro());
        $this->active(10);
        $this->sessions->setPomodoro($this->by, $session->id, self::CLASSIC);
        $this->active(26);
        $this->assertSame(['short_break', 1], [$this->now()->phase, $this->now()->pomodoros]);

        $this->assertThrows(fn () => $this->sessions->setPomodoro($this->by, $session->id, ['focus' => 200] + self::CLASSIC), Unprocessable::class);
        $this->sessions->setPomodoro($this->by, $session->id, null);
        $this->active(20);
        $this->assertSame(['break', false], [$this->now()->state, $this->now()->usesPomodoro()]);
        $this->assertThrows(fn () => $this->sessions->skip($this->by, $session->id), Conflict::class);

        $this->sessions->end($this->by, $session->id);
        $this->assertSame(1, $this->sessions->totals($this->by, $this->databases->id, CarbonImmutable::parse('2026-10-05', 'UTC'))['pomodoros']);
        $records = array_values(array_filter(app(JournalReader::class)->entries($this->learnerScopeOf($this->ada)), fn ($e) => $e->kind->value === 'record' && $e->body['record_type'] === 'session'));
        $this->assertArrayNotHasKey('pomodoros', end($records)->body);
    }

    public function test_settings_are_checked(): void
    {
        foreach ([['focus' => 4], ['short' => 0], ['long' => 61], ['every' => 9], ['focus' => 'x']] as $bad) {
            $this->assertThrows(fn () => $this->sessions->start($this->by, $this->databases->id, pomodoro: $bad + self::CLASSIC), Unprocessable::class);
        }
        $this->assertNull($this->sessions->current($this->by));
    }

    /** $minutes pass with the page in use. */
    private function active(int $minutes): void
    {
        for ($i = 0; $i < $minutes; $i++) {
            $this->travel(1)->minutes();
            $session = $this->sessions->current($this->by);
            if ($session !== null) {
                $this->sessions->touch($this->by, $session->id);
            }
        }
    }

    private function now(): SessionDetails
    {
        return $this->sessions->current($this->by);
    }
}
