<?php

namespace Tests\Feature\Web;

use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\Settings;
use App\Livewire\Workspaces\GuideChat;
use App\Models\User;
use App\Study\Activities;
use App\Study\CourseProfiles;
use App\Study\Modules;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The course guide's page: a talk that proposes, ticks, and adds only what is ticked (docs/specs/vistud-2-blueprint.md, Phase 8). */
class GuideScreenTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private WorkspaceDetails $os;

    private Fake $engine;

    /** What the talk that sets the course up proposes: the course, never its modules. */
    private const PROPOSAL = [
        'course' => ['code' => 'CMP4001', 'term' => 'Autumn 2026'],
        'about' => 'Operating systems, virtualisation and scripting.',
        'assessment' => [['name' => 'Coursework 1', 'kind' => 'assignment', 'weight' => 40, 'due_on' => '2026-11-20']],
    ];

    /** What the talk that adds modules proposes. */
    private const WEEKS = [
        'modules' => [['title' => 'Week 1: OS Structure | Processes & Threads'], ['title' => 'Week 2: Concurrency & Scheduling | Memory Management'], ['title' => 'Week 3: Virtual Memory | Storage & IO']],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-10-08 09:00:00', 'UTC'));
        $this->engine = new Fake;
        $this->app->instance(Engine::class, $this->engine);
        $this->ada = $this->student();
        $by = $this->principal($this->ada);
        $this->os = app(Workspaces::class)->create($by, ['name' => 'Operating Systems']);
        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        app(Settings::class)->set($by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'consent' => true]);
    }

    private function guide(string $for = '')
    {
        $this->actingAs($this->ada);
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        return Livewire::test(GuideChat::class, ['workspaceId' => $this->os->id, 'for' => $for]);
    }

    private function says(array $answer): void
    {
        $this->engine->will(Fake::says((string) json_encode($answer)));
    }

    public function test_the_page_opens_with_the_guide_asking_first_and_nothing_sent_to_the_ai(): void
    {
        $this->actingAs($this->ada)->get(route('workspaces.guide', $this->os->id))
            ->assertOk()->assertSee('<title>Set up · Operating Systems', false)->assertSeeLivewire(GuideChat::class)
            ->assertSee('Set up with the AI')->assertSee('Set the course up myself')->assertSee('Go to Modules')->assertDontSee('Add modules myself')
            ->assertSee('Let&#039;s set up Operating Systems', false)->assertSee('Nothing is added until you tick it');
        // The weeks are the Modules page's: its own talk, which opens from Modules and goes back there.
        $this->actingAs($this->ada)->get(route('workspaces.guide', [$this->os->id, 'for' => 'modules']))->assertOk()->assertSee('Add modules')->assertSee('Which weeks or chapters of Operating Systems')
            ->assertSee('Add modules myself')->assertDontSee('Set the course up myself')->assertSee(route('workspaces.show', [$this->os->id, 'modules']), false);
        $this->assertSame([], $this->engine->requests);
    }

    public function test_the_student_answers_the_guide_proposes_and_nothing_is_added_until_it_is_ticked_and_added(): void
    {
        $this->says(['reply' => 'I found the course. Tick what to add.', 'proposal' => self::PROPOSAL + ['modules' => [['title' => 'Week 1: Not here']]]]);

        $page = $this->guide()->set('text', 'About the Module: operating systems... Week 1 OS Structure | Processes & Threads ...')->call('send')
            ->assertSet('text', '')->assertSee('I found the course.')->assertSee('I would add')
            ->assertSee('What the course is about')->assertSee('Coursework 1')->assertSee('Also add it as an assignment')->assertSee('CMP4001')
            ->assertSee('Add what is ticked (3)')->assertDontSee('Week 1: Not here')->assertDontSee('Modules (');
        $this->assertFalse(app(CourseProfiles::class)->get($this->principal($this->ada), $this->os->id)->hasContent());
        $this->assertSame([], app(Modules::class)->list($this->principal($this->ada), $this->os->id));

        // The course details are not wanted, and the coursework is only noted, not an assignment.
        $page->set('ticks.course', false)->set('ticks.assignments.0', false)->assertSee('Add what is ticked (2)')->call('add')
            ->assertSet('proposal', null)->assertSee('Added: the About text and 1 assessment.')->assertDontSee('I would add')
            ->assertSee('The course is set up.')->assertSee('open Modules')->assertSee('Go to Modules');
        $this->assertSame([], app(Modules::class)->list($this->principal($this->ada), $this->os->id));
        $this->assertSame([], app(Activities::class)->list($this->principal($this->ada), $this->os->id));
        $this->assertSame('Coursework 1', app(CourseProfiles::class)->get($this->principal($this->ada), $this->os->id)->assessment[0]['name']);
        $this->assertNull(app(Workspaces::class)->find($this->principal($this->ada), $this->os->id)->code);
    }

    public function test_the_modules_can_be_added_one_week_at_a_time_and_the_ones_there_are_not_added_twice(): void
    {
        $this->says(['reply' => 'Week 3 is ready.', 'proposal' => ['modules' => [['title' => 'Week 3: Virtual Memory | Storage & IO']]]]);
        app(Modules::class)->create($this->principal($this->ada), $this->os->id, ['title' => 'Week 1: OS Structure | Processes & Threads']);
        app(CourseProfiles::class)->save($this->principal($this->ada), $this->os->id, ['about' => 'Operating systems and virtualisation.']);

        $page = $this->guide('modules')->assertSee('I have read what Operating Systems is about')->assertSee('Which weeks or chapters do you want to add now?')
            ->set('text', 'Add week 3: Virtual Memory | Storage & IO')->call('send')->assertSee('Week 3: Virtual Memory');
        $system = $this->engine->requests[0]->system;
        $this->assertStringContainsString('Modules: Week 1: OS Structure | Processes & Threads', $system);
        $this->assertStringContainsString('About: Operating systems and virtualisation.', $system);
        $page->call('add')->assertSee('Added: 1 module.')->assertSee('Tell me the next weeks whenever you like.')->assertDontSee('Go to Modules');

        $this->says(['reply' => 'Again?', 'proposal' => ['modules' => [['title' => 'week 3: virtual memory | storage & io']]]]);
        $page->set('text', 'Add week 3 again')->call('send')->call('add')->assertSee('Tick what you want to add first.');
        $this->assertCount(2, app(Modules::class)->list($this->principal($this->ada), $this->os->id));
    }

    public function test_none_all_and_not_now_leave_the_course_as_it_was(): void
    {
        $this->says(['reply' => 'These?', 'proposal' => self::WEEKS]);

        $page = $this->guide('modules')->set('text', 'Here is the timetable')->call('send')->assertSee('Add what is ticked (3)')->call('tickModules', false)->assertDontSee('Add what is ticked (');
        $page->call('add')->assertSee('Tick what you want to add first.')->assertSee('I would add');
        $page->call('tickModules', true)->assertSee('Add what is ticked (3)');
        $page->call('drop')->assertSet('proposal', null)->assertSee('I left it out.');
        $this->assertSame([], app(Modules::class)->list($this->principal($this->ada), $this->os->id));

        $this->says(['reply' => 'These?', 'proposal' => self::PROPOSAL]);
        $page = $this->guide()->set('text', 'Here is the page')->call('send')->assertSee('Add what is ticked (3)');
        $page->set('ticks.about', false)->set('ticks.course', false)->set('ticks.assessment.0', false)->call('add')->assertSee('Tick what you want to add first.')->assertSee('I would add');
        $page->call('drop')->assertSet('proposal', null);
        $this->assertFalse(app(CourseProfiles::class)->get($this->principal($this->ada), $this->os->id)->hasContent());
    }

    public function test_the_talk_is_kept_while_the_student_is_here_and_start_over_clears_it(): void
    {
        $this->says(['reply' => 'What is it assessed on?', 'proposal' => null]);

        $this->guide()->set('text', 'It is about operating systems.')->call('send');
        $this->guide()->assertSee('It is about operating systems.')->assertSee('What is it assessed on?')
            ->call('startOver')->assertDontSee('It is about operating systems.')->assertSee('Let&#039;s set up Operating Systems', false);
        $this->guide()->assertDontSee('It is about operating systems.');
    }

    public function test_adding_modules_is_its_own_talk_and_an_old_talk_is_not_picked_up_again(): void
    {
        $this->says(['reply' => 'What is it assessed on?', 'proposal' => null]);
        $this->guide()->set('text', 'It is about operating systems.')->call('send');

        $this->guide('modules')->assertDontSee('It is about operating systems.')->assertSee('Which weeks or chapters of Operating Systems');
        $this->guide()->assertSee('It is about operating systems.');

        $this->travel(3)->hours();
        $this->guide()->assertDontSee('It is about operating systems.')->assertSee('Let&#039;s set up Operating Systems', false);
    }

    public function test_an_empty_message_a_failed_answer_and_a_missing_key_say_what_to_do_and_keep_what_was_written(): void
    {
        $this->guide()->call('send')->assertHasErrors('text')->assertSee('Write something first.');
        $this->assertSame([], $this->engine->requests);

        $this->engine->will(Fake::says('   '));
        $this->guide()->set('text', 'A long page I pasted')->call('send')->assertSet('text', 'A long page I pasted')->assertHasErrors('text')->assertSee('The AI didn&#039;t answer. Try again.', false);

        app(Settings::class)->set($this->principal($this->ada), ['tutor_model' => 'fake/tutor', 'consent' => false]);
        $this->guide()->assertSee('Set up your AI first, then come back.')->assertSee(route('settings', ['part' => 'ai']), false);
    }

    public function test_the_browser_cannot_change_the_course_the_talk_or_the_proposal(): void
    {
        $page = $this->guide();

        $this->assertThrows(fn () => $page->set('workspaceId', 'other'), CannotUpdateLockedPropertyException::class);
        $this->assertThrows(fn () => $page->set('talk', [['from' => 'guide', 'text' => 'x']]), CannotUpdateLockedPropertyException::class);
        $this->assertThrows(fn () => $page->set('proposal', ['modules' => []]), CannotUpdateLockedPropertyException::class);
    }

    public function test_another_students_course_is_missing(): void
    {
        $bob = $this->student();
        $theirs = app(Workspaces::class)->create($this->principal($bob), ['name' => 'Private']);

        $this->actingAs($this->ada)->get(route('workspaces.guide', $theirs->id))->assertNotFound();
    }
}
