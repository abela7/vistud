<?php

namespace Tests\Feature\Study;

use App\Models\User;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\Activities;
use App\Study\Calendar;
use App\Study\CalendarEntry;
use App\Study\Flashcards;
use App\Study\Plans;
use App\Study\Sessions;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The calendar: what the student has with a day, from one workspace or from all (the owner's review, 2026-10-03). */
class CalendarTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private WorkspaceDetails $databases;

    private WorkspaceDetails $biology;

    private Calendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();
        // 22:00 on the 2nd in London's UTC is 01:00 on the 3rd in Addis Ababa: the student's day is what counts.
        $this->travelTo(CarbonImmutable::parse('2026-10-02 22:00:00', 'UTC'));
        $this->ada = $this->student();
        DB::table('learners')->where('user_id', $this->ada->id)->update(['timezone' => 'Africa/Addis_Ababa']);
        $this->by = $this->principal($this->ada);
        $this->databases = app(Workspaces::class)->create($this->by, ['name' => 'Databases', 'colour' => 'blue']);
        $this->biology = app(Workspaces::class)->create($this->by, ['name' => 'Biology', 'colour' => 'green']);
        $this->calendar = app(Calendar::class);
    }

    /** @return list<array{string, string, string}> [day, source, title] */
    private function between(?string $workspaceId, string $from = '2026-10-01', string $to = '2026-10-31'): array
    {
        return array_map(fn (CalendarEntry $e) => [$e->on, $e->source, $e->title], $this->calendar->between($this->by, $workspaceId, $from, $to));
    }

    public function test_deadlines_come_with_their_time_state_and_kind_and_only_those_in_the_range(): void
    {
        $activities = app(Activities::class);
        $essay = $activities->create($this->by, $this->databases->id, ['title' => 'Essay', 'kind' => 'assignment', 'due_on' => '2026-10-10', 'due_time' => '14:00']);
        $exam = $activities->create($this->by, $this->databases->id, ['title' => 'Final exam', 'kind' => 'exam', 'due_on' => '2026-10-10']);
        $late = $activities->create($this->by, $this->databases->id, ['title' => 'Quiz 1', 'kind' => 'quiz', 'due_on' => '2026-10-01']);
        $done = $activities->create($this->by, $this->databases->id, ['title' => 'Lab 1', 'kind' => 'lab', 'due_on' => '2026-10-05']);
        $activities->setStatus($this->by, $done->id, 'done');
        $activities->create($this->by, $this->databases->id, ['title' => 'No day', 'kind' => 'other']);
        $activities->create($this->by, $this->databases->id, ['title' => 'November', 'kind' => 'assignment', 'due_on' => '2026-11-02']);

        $entries = $this->calendar->between($this->by, $this->databases->id, '2026-10-01', '2026-10-31');
        $this->assertSame(
            [['2026-10-01', 'Quiz 1'], ['2026-10-05', 'Lab 1'], ['2026-10-10', 'Essay'], ['2026-10-10', 'Final exam']],
            array_map(fn ($e) => [$e->on, $e->title], $entries),
        );
        $this->assertSame(['late', 'done', 'open', 'open'], array_map(fn ($e) => $e->state, $entries));
        $this->assertSame(['14:00', null], [$entries[2]->at, $entries[3]->at]);
        $this->assertSame(['Quiz', 'Lab', 'Assignment', 'Exam'], array_map(fn ($e) => $e->detail, $entries));
        $this->assertSame([$late->id, $done->id, $essay->id, $exam->id], array_map(fn ($e) => $e->activityId, $entries));
        $this->assertSame(['circle-help', 'flask-conical', 'clipboard-check', 'graduation-cap'], array_map(fn ($e) => $e->icon, $entries));
    }

    public function test_the_steps_parts_and_milestones_of_a_plan_with_a_day_are_on_it(): void
    {
        $plans = app(Plans::class);
        $project = app(Activities::class)->create($this->by, $this->databases->id, ['title' => 'Library system', 'kind' => 'project', 'due_on' => '2026-12-01']);
        $part = $plans->addPart($this->by, $project->id, 'Build');
        $step = $plans->addStep($this->by, $project->id, 'Database', $part->id);
        $stuck = $plans->addStep($this->by, $project->id, 'Screens', $part->id);
        $plans->addStep($this->by, $project->id, 'No day');
        $milestone = $plans->addMilestone($this->by, $project->id, 'Proposal agreed', '2026-10-01');
        $plans->addMilestone($this->by, $project->id, 'Final hand-in', '2026-10-30');
        $plans->update($this->by, $part->id, ['due_on' => '2026-10-20']);
        $plans->update($this->by, $step->id, ['due_on' => '2026-10-08']);
        $plans->update($this->by, $stuck->id, ['due_on' => '2026-10-15']);
        $plans->setState($this->by, $stuck->id, 'stuck');
        $plans->setState($this->by, $step->id, 'done');

        $entries = $this->calendar->between($this->by, $this->databases->id, '2026-10-01', '2026-10-31');
        $this->assertSame(
            [['2026-10-01', 'Proposal agreed'], ['2026-10-08', 'Database'], ['2026-10-15', 'Screens'], ['2026-10-20', 'Build'], ['2026-10-30', 'Final hand-in']],
            array_map(fn ($e) => [$e->on, $e->title], $entries),
        );
        // Today is the 3rd for this student: the 1st is behind them, so an open milestone there is missed.
        $this->assertSame(['late', 'done', 'open', 'open', 'open'], array_map(fn ($e) => $e->state, $entries));
        $this->assertSame(['Milestone · Library system', 'Library system', 'Library system · Stuck', 'Library system · Stuck', 'Milestone · Library system'], array_map(fn ($e) => $e->detail, $entries));
        // (The part is as far as its steps are, so it is stuck too.)
        $this->assertSame(['flag', 'list-checks'], [$entries[0]->icon, $entries[1]->icon]);

        $plans->setState($this->by, $milestone->id, 'achieved');
        $this->assertSame('done', $this->calendar->between($this->by, $this->databases->id, '2026-10-01', '2026-10-01')[0]->state);
    }

    public function test_each_day_studied_is_one_line_and_the_day_is_the_students_own(): void
    {
        $sessions = app(Sessions::class);
        // 23:00 UTC on the 1st is 02:00 on the 2nd in Addis Ababa; the two on the 2nd (local) make one line.
        $sessions->log($this->by, $this->databases->id, ['date' => '2026-10-02', 'time' => '02:00', 'minutes' => '45'], 'Africa/Addis_Ababa');
        $sessions->log($this->by, $this->databases->id, ['date' => '2026-10-02', 'time' => '15:00', 'minutes' => '40'], 'Africa/Addis_Ababa');
        $sessions->log($this->by, $this->databases->id, ['date' => '2026-09-30', 'time' => '10:00', 'minutes' => '20'], 'Africa/Addis_Ababa');

        $entries = $this->calendar->between($this->by, $this->databases->id, '2026-10-01', '2026-10-31');
        $this->assertSame([['2026-10-02', 'studied', 'Studied 1 h 25 min']], array_map(fn ($e) => [$e->on, $e->source, $e->title], $entries));
        $this->assertSame(['2 sessions', 'progress', 'done'], [$entries[0]->detail, $entries[0]->section, $entries[0]->state]);
        $this->assertSame([['2026-09-30', 'studied', 'Studied 20 min']], array_map(fn ($e) => [$e->on, $e->source, $e->title], $this->calendar->between($this->by, $this->databases->id, '2026-09-28', '2026-10-01')));
    }

    public function test_cards_are_shown_on_the_day_they_are_due_and_today_for_what_is_due_now(): void
    {
        $cards = app(Flashcards::class);
        $new = $cards->add($this->by, $this->databases->id, null, 'New card', 'Answer');
        $old = $cards->add($this->by, $this->databases->id, null, 'Waiting card', 'Answer');
        $soon = $cards->add($this->by, $this->databases->id, null, 'Soon card', 'Answer');
        $later = $cards->add($this->by, $this->databases->id, null, 'Later card', 'Answer');
        DB::table('flashcards')->where('id', $old)->update(['due_on' => '2026-09-20']);
        DB::table('flashcards')->where('id', $soon)->update(['due_on' => '2026-10-07']);
        DB::table('flashcards')->where('id', $later)->update(['due_on' => '2026-11-20']);
        $this->assertNotEmpty($new);

        $entries = $this->calendar->between($this->by, $this->databases->id, '2026-10-01', '2026-10-31');
        $this->assertSame([['2026-10-03', 'cards', '2 cards to review'], ['2026-10-07', 'cards', '1 card to review']], array_map(fn ($e) => [$e->on, $e->source, $e->title], $entries));
        $this->assertSame(['Due now', 'Due'], array_map(fn ($e) => $e->detail, $entries));
        // A range that doesn't hold today doesn't show what is due now.
        $this->assertSame([], $this->between($this->databases->id, '2026-10-10', '2026-10-31'));
    }

    public function test_every_workspace_at_once_says_whose_each_thing_is_and_leaves_out_the_archived(): void
    {
        $activities = app(Activities::class);
        $activities->create($this->by, $this->databases->id, ['title' => 'Essay', 'kind' => 'assignment', 'due_on' => '2026-10-10']);
        $activities->create($this->by, $this->biology->id, ['title' => 'Lab report', 'kind' => 'lab', 'due_on' => '2026-10-10', 'due_time' => '09:00']);
        $gone = app(Workspaces::class)->create($this->by, ['name' => 'Old']);
        $activities->create($this->by, $gone->id, ['title' => 'Old thing', 'kind' => 'assignment', 'due_on' => '2026-10-10']);
        app(Workspaces::class)->archive($this->by, $gone->id);

        $entries = $this->calendar->between($this->by, null, '2026-10-01', '2026-10-31');
        $this->assertSame([['Lab report', 'Biology', 'green'], ['Essay', 'Databases', 'blue']], array_map(fn ($e) => [$e->title, $e->workspaceName, $e->colour], $entries));
        // Only one workspace's, even an archived one's own.
        $this->assertSame([['2026-10-10', 'deadline', 'Old thing']], $this->between($gone->id));
    }

    public function test_the_range_is_checked_and_another_students_workspace_is_not_found(): void
    {
        foreach ([['2026-10-31', '2026-10-01'], ['2026-10-01', '2026-02-30'], ['nope', '2026-10-01'], ['2026-01-01', '2026-12-31']] as [$from, $to]) {
            $this->assertThrows(fn () => $this->calendar->between($this->by, null, $from, $to), Unprocessable::class);
        }
        $other = $this->student();
        $theirs = app(Workspaces::class)->create($this->principal($other), ['name' => 'Theirs']);
        app(Activities::class)->create($this->principal($other), $theirs->id, ['title' => 'Secret', 'kind' => 'assignment', 'due_on' => '2026-10-10']);

        $this->assertThrows(fn () => $this->calendar->between($this->by, $theirs->id, '2026-10-01', '2026-10-31'), NotFound::class);
        $this->assertSame([], $this->between(null));
    }
}
