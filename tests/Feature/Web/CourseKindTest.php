<?php

namespace Tests\Feature\Web;

use App\Engine\Context\Stack;
use App\Engine\CourseGuide;
use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\Settings;
use App\Livewire\Workspaces\CourseNew;
use App\Livewire\Workspaces\GuideChat;
use App\Models\User;
use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Study\Briefings;
use App\Study\CourseProfiles;
use App\Study\LearnerProfiles;
use App\Study\Sessions;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * What every AI knows about a course from the start (the owner's ask, 2026-10-12): the kind of course, picked when it is
 * made or asked by the guide with buttons, its level and where the student starts from, then what it is about and the
 * rest in words; each question skippable and never asked again once skipped; the AI only suggests, the student picks.
 * The kind turns on its study plugins in every new session, and the tutor and the copied prompt both read it all.
 */
class CourseKindTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private WorkspaceDetails $course;

    private Fake $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-10-12 09:00:00', 'UTC'));
        $this->engine = new Fake;
        $this->app->instance(Engine::class, $this->engine);
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->course = app(Workspaces::class)->create($this->by, ['name' => 'Databases']);
        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'consent' => true]);
    }

    private function guide()
    {
        $this->actingAs($this->ada);
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        return Livewire::test(GuideChat::class, ['workspaceId' => $this->course->id]);
    }

    public function test_the_kind_is_picked_when_the_course_is_made_and_turns_on_its_plugins_in_new_sessions(): void
    {
        $this->actingAs($this->ada)->get(route('workspaces.create'))->assertOk()->assertSee('What kind of course?')->assertSee('Computing &amp; IT', false);
        $this->actingAs($this->ada);
        Livewire::test(CourseNew::class)->set('name', 'Programming 1')->set('type', 'computing')->set('how', 'myself')->call('create');
        $made = collect(app(Workspaces::class)->list($this->by))->firstWhere('name', 'Programming 1');
        $this->assertSame('computing', $made->type);

        // The journal's record of the course says its subject.
        $record = LearnerTables::query(Guard::learner($this->by), 'journal_entries')->where('kind', 'record')->orderByDesc('position')->value('body');
        $this->assertSame('computing', json_decode((string) $record, true)['subject']);

        $session = app(Sessions::class)->start($this->by, $made->id);
        $this->assertSame(['coding'], $session->tutoring['plugins']);
        app(Sessions::class)->end($this->by, $session->id);

        // A course's own choice of plugins wins over its kind's; none for a course without a kind.
        app(CourseProfiles::class)->saveFacts($this->by, $made->id, ['plugins' => ['coding', 'math_physics']]);
        $this->assertSame(['coding', 'math_physics'], app(CourseProfiles::class)->plugins($this->by, $made->id));
        $this->assertSame([], app(CourseProfiles::class)->plugins($this->by, $this->course->id));
        $this->assertThrows(fn () => app(Workspaces::class)->setType($this->by, $made->id, 'astrology'));
    }

    public function test_the_guide_asks_with_buttons_writes_a_pick_at_once_and_never_asks_a_skipped_question_again(): void
    {
        $page = $this->guide()->assertSee('What kind of course is it?')->assertSet('talk.1.ask', 'type');

        $page->call('choose', 'type', 'computing')->assertSee('What level is it taught at?')->assertSee('Undergraduate');
        $this->assertSame('computing', app(Workspaces::class)->find($this->by, $this->course->id)->type);
        $page->call('choose', 'level', 'undergraduate')->assertSee('Where are you starting from in it?');
        $page->call('skip', 'background')->assertSee('What is the course about?');
        $this->assertSame([], $this->engine->requests, 'buttons and Skip ask no AI');

        $states = app(CourseGuide::class)->questions($this->by, $this->course->id);
        $this->assertSame(['type' => 'done', 'level' => 'done', 'background' => 'skipped', 'about' => 'open'], array_slice($states, 0, 4));

        // The AI is told what is skipped and what to ask next, in words that suit the kind of course.
        $this->engine->will(Fake::says((string) json_encode(['reply' => 'Thanks. How is it assessed?', 'proposal' => null])));
        $page->set('text', 'Relational databases and SQL.')->call('send')->assertSee('Thanks. How is it assessed?');
        $system = $this->engine->requests[0]->system;
        $this->assertStringContainsString('Kind: Computing & IT', $system);
        $this->assertStringContainsString('Level: Undergraduate', $system);
        $this->assertStringContainsString('Skipped, never ask: background', $system);
        $this->assertStringContainsString('Ask next: What is the course about?', $system);

        // Asked again from the list of what the AI knows, a skipped question is open once more.
        $page->call('askAgain', 'background')->assertSet('talk.'.(count($page->get('talk')) - 1).'.ask', 'background');
        $this->assertSame('open', app(CourseGuide::class)->questions($this->by, $this->course->id)['background']);
        $page->call('choose', 'background', 'revising');
        $this->assertSame('revising', app(LearnerProfiles::class)->get($this->by, $this->course->id)->background);

        // A pick that isn't one of the buttons is refused.
        $page->call('choose', 'level', 'wizard');
        $this->assertSame('undergraduate', app(CourseProfiles::class)->get($this->by, $this->course->id)->level);
    }

    public function test_what_the_ai_suggests_about_the_course_is_added_only_when_ticked(): void
    {
        $this->engine->will(Fake::says((string) json_encode(['reply' => 'Tick what fits.', 'proposal' => [
            'facts' => ['type' => 'computing', 'level' => 'undergraduate', 'language' => 'English', 'tools' => ['MySQL', 'SQL', 'mysql'], 'exam_format' => 'Closed book, 2 hours', 'colour' => 'red'],
            'about' => 'Relational databases and SQL.',
        ]])));
        $page = $this->guide()->set('text', 'Here is the module page.')->call('send')
            ->assertSee('About the course')->assertSee('Computing &amp; IT (suggested)', false)->assertSee('MySQL, SQL')->assertSee('Add what is ticked (6)');

        $page->set('ticks.facts.type', false)->call('add')->assertSee('Added: the About text and what kind of course it is.');
        $profile = app(CourseProfiles::class)->get($this->by, $this->course->id);
        $this->assertSame(['undergraduate', 'English', ['MySQL', 'SQL'], 'Closed book, 2 hours'], [$profile->level, $profile->language, $profile->tools, $profile->examFormat]);
        $this->assertSame('general', app(Workspaces::class)->find($this->by, $this->course->id)->type, 'the unticked suggestion is not taken');
    }

    public function test_the_tutor_and_the_copied_prompt_both_know_the_course_and_where_the_student_starts(): void
    {
        app(Workspaces::class)->setType($this->by, $this->course->id, 'computing');
        app(CourseProfiles::class)->saveFacts($this->by, $this->course->id, ['level' => 'undergraduate', 'language' => 'English', 'tools' => 'MySQL, Python', 'exam_format' => 'Closed book']);
        app(CourseProfiles::class)->save($this->by, $this->course->id, ['about' => 'Relational databases and SQL.']);
        app(LearnerProfiles::class)->setBackground($this->by, $this->course->id, 'new');
        // Saving how they like to learn keeps where they start from.
        app(LearnerProfiles::class)->save($this->by, $this->course->id, ['pace' => 'small']);
        $session = app(Sessions::class)->start($this->by, $this->course->id);

        $tutor = app(Stack::class)->build($this->by, $session, null, true)->system;
        foreach (['Kind: computing and IT · undergraduate university · taught in English', 'Tools and notation: MySQL, Python', 'Exams: Closed book', 'Starting point: new to the subject'] as $line) {
            $this->assertStringContainsString($line, $tutor);
        }

        $prompt = app(Briefings::class)->forSession($this->by, $session->id)->markdown;
        foreach (['Kind: computing and IT', 'About: Relational databases and SQL.', 'The student: Starting point: new to the subject', 'The student: Pace: small steps'] as $line) {
            $this->assertStringContainsString($line, $prompt);
        }
    }

    public function test_a_reply_streams_as_it_is_written(): void
    {
        $this->assertSame('', CourseGuide::replySoFar('{"rep'));
        $this->assertSame('Hello th', CourseGuide::replySoFar('{"reply": "Hello th'));
        $this->assertSame('Line one', CourseGuide::replySoFar('{"reply": "Line one\\'));
        $this->assertSame("Line one\nTwo \"quoted\"", CourseGuide::replySoFar('{"reply": "Line one\\nTwo \\"quoted\\"", "proposal": null}'));
        $this->assertSame('Plain words', CourseGuide::replySoFar('Plain words'));
    }
}
