<?php

namespace Tests\Feature\Web;

use App\Livewire\Workspaces\CalendarBoard;
use App\Models\User;
use App\Study\Activities;
use App\Study\Plans;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The calendar on its pages: a month of days, the day picked, the agenda, and what can be hidden (the owner's review, 2026-10-03). */
class CalendarScreensTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private WorkspaceDetails $databases;

    private WorkspaceDetails $biology;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-02 09:00', 'UTC'));
        $this->ada = $this->student();
        $by = $this->principal($this->ada);
        $this->databases = app(Workspaces::class)->create($by, ['name' => 'Databases']);
        $this->biology = app(Workspaces::class)->create($by, ['name' => 'Biology']);
        $activities = app(Activities::class);
        $essay = $activities->create($by, $this->databases->id, ['title' => 'Cell essay', 'kind' => 'assignment', 'due_on' => '2026-10-06', 'due_time' => '09:00']);
        $activities->create($by, $this->databases->id, ['title' => 'Final exam', 'kind' => 'exam', 'due_on' => '2026-10-20']);
        $activities->create($by, $this->biology->id, ['title' => 'Lab report', 'kind' => 'lab', 'due_on' => '2026-10-06']);
        $activities->create($by, $this->databases->id, ['title' => 'November quiz', 'kind' => 'quiz', 'due_on' => '2026-11-04']);
        app(Plans::class)->addMilestone($by, $essay->id, 'First draft', '2026-10-03');
        $this->actingAs($this->ada);
    }

    private function board(?string $workspaceId = null)
    {
        return Livewire::test(CalendarBoard::class, ['workspaceId' => $workspaceId]);
    }

    public function test_a_workspaces_calendar_is_its_section_and_the_one_across_all_is_its_own_page(): void
    {
        $this->get(route('workspaces.show', [$this->databases->id, 'calendar']))->assertOk()->assertSee('October 2026')->assertSee('Cell essay')->assertDontSee('Lab report')->assertDontSee('is coming next');
        $this->get(route('calendar.index'))->assertOk()->assertSee('from all your courses')->assertSee('Cell essay')->assertSee('Lab report');
        $this->get(route('workspaces.show', [$this->databases->id, 'overview']))->assertOk()->assertSee('Calendar');
        auth()->logout();
        $this->get(route('calendar.index'))->assertRedirect();
    }

    public function test_a_month_shows_its_weeks_from_monday_with_what_is_on_each_day_and_today_marked(): void
    {
        $component = $this->board($this->databases->id)->assertSee('October 2026')->assertSee('Cell essay')->assertSee('First draft')->assertSee('Final exam')
            ->assertSee('Wednesday 7 October, nothing', false)->assertSee('Tuesday 6 October, 1 thing', false)->assertSee('aria-current="date"', false)->assertDontSee('November quiz');
        // October 2026 begins on a Thursday: the grid starts on Monday 28 September and takes five weeks.
        $component->assertSee('Monday 28 September, nothing', false)->assertSee('Saturday 31 October, nothing', false)->assertDontSee('Monday 2 November');
        $component->assertSet('view', 'month')->assertSee('Nothing on this day.');
    }

    public function test_months_are_moved_between_and_the_address_holds_the_month_and_the_view(): void
    {
        $component = $this->board($this->databases->id)->call('next')->assertSet('month', '2026-11')->assertSee('November 2026')->assertSee('November quiz')->assertDontSee('Cell essay');
        $component->call('previous')->call('previous')->assertSet('month', '2026-09')->assertSee('September 2026')->call('today')->assertSet('month', '2026-10');
        $component->set('month', '2027-01')->assertSee('January 2027')->call('previous')->assertSee('December 2026');
        // Nonsense in the address is today's month.
        $this->board($this->databases->id)->set('month', 'soon')->assertSee('October 2026')->set('month', '2026-13')->assertSee('October 2026')->set('month', '1999-12')->assertSee('October 2026');
        $this->get(route('workspaces.show', [$this->databases->id, 'calendar']).'?m=2026-11&view=agenda')->assertOk()->assertSee('November 2026')->assertSee('Wednesday 4 November');
    }

    public function test_the_agenda_lists_the_days_that_have_something_and_what_is_hidden_goes(): void
    {
        $component = $this->board($this->databases->id)->call('show', 'agenda')->assertSet('view', 'agenda')
            ->assertSeeInOrder(['Saturday 3 October', 'First draft', 'Tuesday 6 October', 'Cell essay', 'Tuesday 20 October', 'Final exam'])->assertDontSee('Sunday 4 October');
        $component->call('toggle', 'plan')->assertDontSee('First draft')->assertSee('Cell essay')->assertSet('hidden', ['plan'])
            ->call('toggle', 'deadline')->assertSee('Nothing in October of what is shown.')->call('toggle', 'plan')->call('toggle', 'deadline')->assertSee('First draft')
            ->call('toggle', 'nonsense')->assertSet('hidden', [])->call('show', 'whatever')->assertSet('view', 'month');
    }

    public function test_the_calendar_across_all_says_whose_each_thing_is(): void
    {
        $this->board()->assertSee('Cell essay')->assertSee('Lab report')->assertSee('Databases')->assertSee('Biology')->call('show', 'agenda')->assertSee('09:00 · Assignment · Databases')->assertSee('Lab · Biology');
        $this->board($this->biology->id)->assertSee('Lab report')->assertDontSee('Cell essay')->call('show', 'agenda')->assertSee('Lab')->assertDontSee('· Biology');
    }

    public function test_things_say_how_they_stand(): void
    {
        $by = $this->principal($this->ada);
        Carbon::setTestNow(Carbon::parse('2026-10-08 09:00', 'UTC'));
        $this->board($this->databases->id)->call('show', 'agenda')->assertSee('Overdue')->assertSee('Missed');
        $done = app(Activities::class)->create($by, $this->databases->id, ['title' => 'Handed in', 'kind' => 'assignment', 'due_on' => '2026-10-07']);
        app(Activities::class)->setStatus($by, $done->id, 'done');
        $this->board($this->databases->id)->call('show', 'agenda')->assertSee('Done');
    }

    public function test_another_students_workspace_is_not_found_and_the_ids_are_locked(): void
    {
        $bob = $this->student();
        $theirs = app(Workspaces::class)->create($this->principal($bob), ['name' => 'Private']);
        $this->get(route('workspaces.show', [$theirs->id, 'calendar']))->assertNotFound();
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $this->board($this->databases->id)->set('workspaceId', $theirs->id);
    }
}
