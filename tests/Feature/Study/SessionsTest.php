<?php

namespace Tests\Feature\Study;

use App\Brain\Store\JournalReader;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Platform\Ids;
use App\Study\SessionDetails;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsJournalEntries;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** Study sessions and their clock (docs/specs/study-memory.md §4). */
class SessionsTest extends TestCase
{
    use BuildsJournalEntries, CreatesAccounts, RefreshesDatabase;

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

    public function test_study_and_breaks_are_counted_apart_and_a_pause_counts_nothing(): void
    {
        $joins = app(Topics::class)->create($this->by, $this->databases->id, 'Joins');
        $session = $this->sessions->start($this->by, $this->databases->id, $joins->id);
        $this->assertSame(['running', $joins->id, 0], [$session->state, $session->topicId, $session->studySeconds]);

        $this->minutes(10);
        $this->assertSame(600, $this->sessions->current($this->by)->studySeconds);
        $this->sessions->pause($this->by, $session->id);
        $this->minutes(5);
        $this->sessions->takeBreak($this->by, $session->id);
        $this->minutes(7);
        $onBreak = $this->sessions->current($this->by);
        $this->assertSame(['break', 600, 420], [$onBreak->state, $onBreak->studySeconds, $onBreak->breakSeconds]);
        $this->sessions->resume($this->by, $session->id);
        $this->minutes(20);
        $ended = $this->sessions->end($this->by, $session->id);

        $this->assertSame(['ended', 1800, 420, '30 min', '7 min'], [$ended->state, $ended->studySeconds, $ended->breakSeconds, SessionDetails::duration($ended->studySeconds), SessionDetails::duration($ended->breakSeconds)]);
        $this->assertSame([['study', 'pause'], ['break', 'resume'], ['study', 'end']], array_map(fn ($s) => [$s->kind, $s->endedBy], $ended->segments));
        $this->assertNull($this->sessions->current($this->by));

        $records = $this->records($session->id);
        $this->assertSame([[1, 'open'], [2, 'ended']], array_map(fn ($e) => [$e->body['revision'], $e->body['status']], $records));
        $this->assertSame([1800, 420, $joins->id], [end($records)->body['study_seconds'], end($records)->body['break_seconds'], end($records)->body['topic']]);

        $this->assertThrows(fn () => $this->sessions->resume($this->by, $session->id), Conflict::class);
    }

    public function test_one_session_is_open_at_a_time(): void
    {
        $session = $this->sessions->start($this->by, $this->databases->id);
        $maths = app(Workspaces::class)->create($this->by, ['name' => 'Maths']);

        $this->assertThrows(fn () => $this->sessions->start($this->by, $maths->id), Conflict::class);
        $this->assertThrows(fn () => $this->sessions->resume($this->by, $session->id), Conflict::class);

        // The database keeps it too: a second open session can't be written even past the service's check (two tabs at once).
        $row = (array) DB::table('study_sessions')->where('id', $session->id)->first();
        $this->assertThrows(fn () => DB::table('study_sessions')->insert(['id' => Ids::new()] + Arr::except($row, ['id', 'open_learner'])), UniqueConstraintViolationException::class);

        $this->sessions->end($this->by, $session->id);
        $this->assertSame('running', $this->sessions->start($this->by, $maths->id)->state);
    }

    public function test_the_clock_pauses_itself_when_the_student_is_away_and_they_can_count_it_back(): void
    {
        $session = $this->sessions->start($this->by, $this->databases->id);
        $this->minutes(10);
        $this->sessions->touch($this->by, $session->id);
        $this->minutes(Sessions::IDLE_MINUTES + 5);

        $away = $this->sessions->current($this->by);
        $this->assertSame(['paused', 'away', 600], [$away->state, $away->pausedBy, $away->studySeconds]);

        // They were reading on paper: the time counts, and the clock runs on.
        $this->sessions->countAway($this->by, $session->id);
        $this->minutes(5);
        $this->assertSame(['running', (10 + Sessions::IDLE_MINUTES + 5 + 5) * 60], [$this->sessions->current($this->by)->state, $this->sessions->current($this->by)->studySeconds]);

        // Only an automatic pause can be counted back.
        $this->sessions->pause($this->by, $session->id);
        $this->assertThrows(fn () => $this->sessions->countAway($this->by, $session->id), Conflict::class);
    }

    public function test_a_long_break_ends_by_itself_and_a_forgotten_session_ends_at_its_last_activity(): void
    {
        $session = $this->sessions->start($this->by, $this->databases->id);
        $this->minutes(15);
        $this->sessions->takeBreak($this->by, $session->id);
        $this->minutes(Sessions::BREAK_MINUTES + 30);

        $paused = $this->sessions->current($this->by);
        $this->assertSame(['paused', 'long_break', 900, Sessions::BREAK_MINUTES * 60], [$paused->state, $paused->pausedBy, $paused->studySeconds, $paused->breakSeconds]);

        $this->travel(Sessions::AUTO_END_HOURS + 1)->hours();
        $this->assertNull($this->sessions->current($this->by));
        $ended = $this->sessions->find($this->by, $session->id);
        $this->assertSame(['ended', 900], [$ended->state, $ended->studySeconds]);
        $this->assertSame('2026-10-05T10:15:00.000000Z', $ended->endedAt);
        $records = $this->records($session->id);
        $this->assertSame('ended', end($records)->body['status']);
    }

    public function test_the_scheduler_settles_sessions_nobody_opens_again(): void
    {
        $session = $this->sessions->start($this->by, $this->databases->id);
        $this->minutes(Sessions::IDLE_MINUTES + 1);

        $this->artisan('vistud:sessions:settle')->expectsOutput('Settled 1 session.')->assertSuccessful();
        $this->assertSame(['paused', 'away'], [$this->sessions->find($this->by, $session->id)->state, $this->sessions->find($this->by, $session->id)->pausedBy]);
    }

    public function test_evidence_written_during_a_session_carries_its_id(): void
    {
        $joins = app(Topics::class)->create($this->by, $this->databases->id, 'Joins');
        $maths = app(Workspaces::class)->create($this->by, ['name' => 'Maths']);
        $sets = app(Topics::class)->create($this->by, $maths->id, 'Sets');
        $session = $this->sessions->start($this->by, $this->databases->id, $joins->id);

        app(Topics::class)->report($this->by, $joins->id, 'understood');
        app(Topics::class)->report($this->by, $sets->id, 'understood');

        $reports = array_values(array_filter($this->entries(), fn ($e) => $e->kind->value === 'self_report'));
        $this->assertSame([$session->id, null], array_map(fn ($e) => $e->sessionId, $reports));
    }

    public function test_time_studied_without_the_clock_is_logged_afterwards_in_the_students_time_zone(): void
    {
        $joins = app(Topics::class)->create($this->by, $this->databases->id, 'Joins');
        $logged = $this->sessions->log($this->by, $this->databases->id, ['date' => '2026-10-04', 'time' => '14:00', 'minutes' => '45', 'topic_id' => $joins->id], 'Europe/London');

        $this->assertSame(['ended', true, 2700, '2026-10-04T13:00:00.000000Z', '2026-10-04T13:45:00.000000Z'], [$logged->state, $logged->manual, $logged->studySeconds, $logged->startedAt, $logged->endedAt]);
        $this->assertSame([['study', 'log']], array_map(fn ($s) => [$s->kind, $s->endedBy], $logged->segments));
        $this->assertSame('logged', $this->records($logged->id)[0]->body['client']);

        foreach ([
            ['date' => '2026-10-04', 'time' => '14:00', 'minutes' => '0'],
            ['date' => '2026-10-04', 'time' => '14:00', 'minutes' => (string) (Sessions::MAX_LOG_MINUTES + 1)],
            ['date' => '2026-02-30', 'time' => '14:00', 'minutes' => '30'],
            ['date' => '2026-10-05', 'time' => '10:30', 'minutes' => '30'],
        ] as $bad) {
            $this->assertThrows(fn () => $this->sessions->log($this->by, $this->databases->id, $bad, 'Europe/London'), Unprocessable::class);
        }
    }

    public function test_totals_count_the_week_and_all_time_and_a_deleted_session_leaves_them(): void
    {
        $this->sessions->log($this->by, $this->databases->id, ['date' => '2026-09-28', 'time' => '10:00', 'minutes' => '60'], 'UTC');
        $this->sessions->log($this->by, $this->databases->id, ['date' => '2026-10-02', 'time' => '10:00', 'minutes' => '30'], 'UTC');
        $open = $this->sessions->start($this->by, $this->databases->id);
        $this->minutes(20);
        $weekStart = CarbonImmutable::parse('2026-09-29 00:00', 'UTC');

        $this->assertSame(['since' => 50 * 60, 'all' => 110 * 60, 'sessions' => 3, 'pomodoros_since' => 0, 'pomodoros' => 0], $this->sessions->totals($this->by, $this->databases->id, $weekStart));
        $this->assertCount(3, $this->sessions->list($this->by, $this->databases->id));

        $this->sessions->delete($this->by, $open->id);
        $this->assertSame(['since' => 30 * 60, 'all' => 90 * 60, 'sessions' => 2, 'pomodoros_since' => 0, 'pomodoros' => 0], $this->sessions->totals($this->by, $this->databases->id, $weekStart));
        $records = $this->records($open->id);
        $this->assertSame([2, 'deleted'], [end($records)->body['revision'], end($records)->body['status']]);
        $this->assertNull($this->sessions->current($this->by));
    }

    public function test_the_rhythm_is_the_last_7_days_and_the_streak_across_courses(): void
    {
        $biology = app(Workspaces::class)->create($this->by, ['name' => 'Biology']);
        // Today is Monday 5 October (UTC). A gap on the 1st, then three days in a row.
        $this->sessions->log($this->by, $this->databases->id, ['date' => '2026-09-30', 'time' => '10:00', 'minutes' => '20'], 'UTC');
        $this->sessions->log($this->by, $this->databases->id, ['date' => '2026-10-02', 'time' => '10:00', 'minutes' => '30'], 'UTC');
        $this->sessions->log($this->by, $biology->id, ['date' => '2026-10-03', 'time' => '10:00', 'minutes' => '15'], 'UTC');
        $this->sessions->log($this->by, $this->databases->id, ['date' => '2026-10-04', 'time' => '10:00', 'minutes' => '45'], 'UTC');

        $rhythm = $this->sessions->rhythm($this->by, $this->databases->id);
        $this->assertSame(['2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04', '2026-10-05'], array_keys($rhythm['days']));
        $this->assertSame([0, 1200, 0, 1800, 0, 2700, 0], array_values($rhythm['days']));
        // Nothing yet today: the streak still counts up to yesterday, in any course.
        $this->assertSame(3, $rhythm['streak']);
        $this->assertSame(900, $this->sessions->rhythm($this->by)['days']['2026-10-03']);

        $this->sessions->start($this->by, $this->databases->id);
        $this->minutes(10);
        $this->assertSame([4, 600], [$this->sessions->rhythm($this->by, $this->databases->id)['streak'], $this->sessions->rhythm($this->by, $this->databases->id)['days']['2026-10-05']]);

        // Two days without study break it.
        $this->travel(3)->days();
        $this->assertSame(0, $this->sessions->rhythm($this->by)['streak']);
    }

    public function test_another_students_sessions_are_missing(): void
    {
        $bob = $this->student();
        $theirs = app(Workspaces::class)->create($this->principal($bob), ['name' => 'Private']);
        $session = $this->sessions->start($this->principal($bob), $theirs->id);

        foreach ([
            fn () => $this->sessions->find($this->by, $session->id),
            fn () => $this->sessions->pause($this->by, $session->id),
            fn () => $this->sessions->end($this->by, $session->id),
            fn () => $this->sessions->delete($this->by, $session->id),
            fn () => $this->sessions->start($this->by, $theirs->id),
            fn () => $this->sessions->log($this->by, $theirs->id, ['date' => '2026-10-04', 'time' => '10:00', 'minutes' => '5'], 'UTC'),
        ] as $attempt) {
            $this->assertThrows($attempt, NotFound::class);
        }
        $this->assertNull($this->sessions->current($this->by));
        $this->assertSame('running', $this->sessions->current($this->principal($bob))->state);
    }

    private function minutes(int $minutes): void
    {
        $this->travel($minutes)->minutes();
    }

    private function entries(): array
    {
        return app(JournalReader::class)->entries($this->learnerScopeOf($this->ada));
    }

    private function records(string $sessionId): array
    {
        return array_values(array_filter($this->entries(), fn ($e) => $e->kind->value === 'record' && $e->body['record_type'] === 'session' && $e->body['record_id'] === $sessionId));
    }
}
