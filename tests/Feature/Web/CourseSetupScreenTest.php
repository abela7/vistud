<?php

namespace Tests\Feature\Web;

use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\Settings;
use App\Livewire\Workspaces\CourseSetup;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Study\Activities;
use App\Study\CourseProfiles;
use App\Study\LearnerProfiles;
use App\Study\Modules;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** Setting a course up: what it is (a syllabus read by the reader, or written by hand) and how the student likes to learn. */
class CourseSetupScreenTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private string $workspace;

    private Fake $engine;

    private const ANSWER = [
        'about' => 'Processes, memory and file systems.',
        'outcomes' => ['Explain scheduling policies'],
        'assessment' => [['name' => 'Midterm', 'kind' => 'exam', 'weight' => 30, 'due_on' => '2026-10-12'], ['name' => 'Final', 'kind' => 'exam', 'weight' => 40, 'due_on' => null]],
        'textbook' => 'Operating System Concepts',
        'modules' => [['title' => 'Week 1: Introduction', 'starts_on' => '2026-09-14', 'ends_on' => '2026-09-20'], ['title' => 'Week 2: Processes', 'starts_on' => null, 'ends_on' => null]],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 09:00:00', 'UTC'));
        $this->engine = new Fake;
        $this->app->instance(Engine::class, $this->engine);
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->workspace = app(Workspaces::class)->create($this->by, ['name' => 'Operating Systems'])->id;
    }

    private function ready(): void
    {
        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'consent' => true]);
    }

    private function sheet(bool $openOnLoad = false)
    {
        $this->actingAs($this->ada);
        // Livewire's test requests skip middleware, so nothing gives them the session.
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        return Livewire::test(CourseSetup::class, ['workspaceId' => $this->workspace, 'openOnLoad' => $openOnLoad]);
    }

    public function test_a_new_course_opens_its_page_with_the_sheet_on_step_two_and_the_menu_opens_it_later(): void
    {
        $this->actingAs($this->ada)->get(route('workspaces.show', ['workspace' => $this->workspace, 'setup' => 1]))
            ->assertOk()->assertSee('id="course-setup"', false)->assertSee('Step 2 of 3')->assertSee('What is this course?')
            ->assertSee('About this course')->assertSee('How you learn');

        $this->sheet()->assertSet('flow', false)->assertSet('step', 'about')->assertDontSee('Step 2 of 3')
            ->dispatch('course-setup-open', step: 'learn')->assertSet('step', 'learn')->assertSee('How do you like to learn?')
            ->assertDispatched('course-setup-dialog-open');
    }

    public function test_without_an_ai_set_up_it_says_so_and_the_student_can_write_it_by_hand_or_skip(): void
    {
        $this->sheet(true)->assertSet('flow', true)
            ->assertSee('Set up your AI first, then read the syllabus.')->assertSee(route('settings', ['part' => 'ai']), false)
            ->assertDontSee('Read it')->assertSee('Write it myself')
            ->call('write')->assertSee('How it is assessed')
            ->call('next')->assertSet('step', 'learn')->assertSee('Step 3 of 3');
        // Nothing was sent, and no run was made.
        $this->assertSame([], $this->engine->requests);
    }

    public function test_pasted_text_is_read_and_the_student_ticks_what_to_add(): void
    {
        $this->ready();
        $this->engine->will(Fake::says(json_encode(self::ANSWER), 20_000, 'fake/quick'));

        $page = $this->sheet(true)
            ->set('syllabusText', "Week 1: Introduction\nWeek 2: Processes")->call('read')
            ->assertSet('jobId', null)->assertSet('problem', null)
            // The findings fill the sheet; the modules and the dated assessment come ticked.
            ->assertSet('about', 'Processes, memory and file systems.')->assertSet('textbook', 'Operating System Concepts')
            ->assertSee('Modules found (2)')->assertSee('Week 1: Introduction · 14 Sep – 20 Sep')->assertSee('Week 2: Processes')
            ->assertSee('Add 2 modules')->assertSet('moduleTicks', [true, true])->assertSet('assessmentTicks', [true, false])
            ->assertSee('Add as an assignment');
        $this->assertSame([], app(Modules::class)->list($this->by, $this->workspace));

        $page->set('moduleTicks.1', false)->assertSee('Add 1 module')
            ->set('assessment.0.due_on', '2026-10-13')
            ->call('add')->assertSet('step', 'learn')->assertSet('added', '1 module and 1 assignment added.')
            // On to How do you like to learn?, and the notice is said where the sheet ends.
            ->call('saveLearn')->assertRedirect(route('workspaces.show', $this->workspace));
        $this->assertSame('1 module and 1 assignment added.', session('workspace-notice'));

        $this->assertSame(['Week 1: Introduction'], array_map(fn ($m) => $m->title, app(Modules::class)->list($this->by, $this->workspace)));
        $tasks = app(Activities::class)->list($this->by, $this->workspace);
        $this->assertSame([['Midterm', '2026-10-13']], array_map(fn ($t) => [$t->title, $t->dueOn], $tasks));
        $profile = app(CourseProfiles::class)->get($this->by, $this->workspace);
        $this->assertSame([], $profile->proposedModules);
        $this->assertSame('Operating System Concepts', $profile->textbook);
    }

    public function test_a_file_is_read_too(): void
    {
        // Livewire clears uploads older than a day: the clock stays real for an upload.
        $this->travelBack();
        $this->ready();
        $this->engine->will(Fake::says(json_encode(self::ANSWER)));

        $this->sheet()->set('file', UploadedFile::fake()->createWithContent('syllabus.txt', "Week 1: Introduction\nWeek 2: Processes\n"))
            ->call('read')->assertSet('problem', null)->assertSee('Modules found (2)');

        $profile = app(CourseProfiles::class)->get($this->by, $this->workspace);
        $this->assertSame('file', $profile->source);
        $this->assertStringContainsString('Week 2: Processes', $this->engine->requests[0]->messages[0]['content']);
    }

    public function test_nothing_to_read_and_an_unreadable_answer_each_say_what_to_do_next(): void
    {
        $this->ready();
        $this->sheet()->call('read')->assertHasErrors(['syllabus'])->assertSee('Drop a file or paste the text.');

        $this->engine->will(Fake::says('I cannot read that.'));
        $this->sheet()->set('syllabusText', 'Week 1')->call('read')->assertSet('problem', 'engine_unreadable')->assertSee('The AI\'s answer couldn\'t be read. Try again.');

        // A picture has no words.
        $this->travelBack();
        $this->sheet()->set('file', UploadedFile::fake()->image('syllabus.png'))->call('read')->assertSet('problem', 'no_text')->assertSee('No words found in it. Paste the syllabus text instead.');
    }

    public function test_writing_it_by_hand_is_kept_and_a_bad_row_is_refused_on_its_row(): void
    {
        $this->sheet()->call('write')->set('about', 'A course on processes.')->set('outcomes', "Explain scheduling\nWrite a shell")->set('textbook', 'Tanenbaum')
            ->call('addRow')->set('assessment.0.name', '')->set('assessment.0.weight', '30')->set('assessment.0.due_on', '2026-10-12')
            ->call('add')->assertHasErrors(['assessment.0'])->assertSee('Name it.');

        $this->sheet()->call('write')->set('about', 'A course on processes.')->set('outcomes', "Explain scheduling\nWrite a shell")->set('textbook', 'Tanenbaum')
            ->call('addRow')->set('assessment.0.name', 'Midterm')->set('assessment.0.kind', 'exam')->set('assessment.0.weight', '30')->set('assessment.0.due_on', '2026-10-12')
            ->call('add')->assertHasNoErrors()->assertRedirect(route('workspaces.show', $this->workspace));

        $profile = app(CourseProfiles::class)->get($this->by, $this->workspace);
        $this->assertSame(['A course on processes.', ['Explain scheduling', 'Write a shell'], 'Tanenbaum'], [$profile->about, $profile->outcomes, $profile->textbook]);
        // The dated item was ticked by default: it became an assignment.
        $this->assertSame(['Midterm'], array_map(fn ($t) => $t->title, app(Activities::class)->list($this->by, $this->workspace)));

        // Opened again later, what was written is there.
        $this->sheet()->assertSet('about', 'A course on processes.')->assertSet('writing', true)->assertSee('Added as an assignment');
    }

    public function test_a_proposal_can_be_dismissed_and_the_modules_ticked_all_or_none(): void
    {
        $this->ready();
        $this->engine->will(Fake::says(json_encode(self::ANSWER)));

        $this->sheet()->set('syllabusText', 'Week 1')->call('read')
            ->call('tickModules', false)->assertSet('moduleTicks', [false, false])->assertSee('Save')->assertDontSee('Add 0 modules')
            ->call('tickModules', true)->assertSee('Add 2 modules')
            ->call('dismiss')->assertDontSee('Modules found');
    }

    public function test_how_you_like_to_learn_is_four_short_questions_all_optional(): void
    {
        $this->sheet()->dispatch('course-setup-open', step: 'learn')
            ->assertSee('Explain with')->assertSee('Examples first')->assertSee('Small steps')->assertSee('Check me')->assertSee('Understand deeply')
            ->set('explain', ['examples', 'diagrams'])->set('pace', 'small')->set('check', 'often')->set('goal', 'top')->set('note', 'Use C.')
            ->call('saveLearn')->assertRedirect(route('workspaces.show', $this->workspace));

        $learn = app(LearnerProfiles::class)->get($this->by, $this->workspace);
        $this->assertSame([['examples', 'diagrams'], 'small', 'often', 'top', 'Use C.'], [$learn->explain, $learn->pace, $learn->check, $learn->goal, $learn->note]);

        // Reopened, the answers are there; left empty, nothing is answered.
        $this->sheet()->dispatch('course-setup-open', step: 'learn')->assertSet('pace', 'small')->assertSet('explain', ['examples', 'diagrams'])
            ->set('explain', [])->set('pace', '')->set('check', '')->set('goal', '')->set('note', '')->call('saveLearn')->assertHasNoErrors();
        $this->assertFalse(app(LearnerProfiles::class)->get($this->by, $this->workspace)->answered());
    }

    public function test_another_students_course_has_no_sheet(): void
    {
        $bob = $this->student();
        $this->actingAs($bob)->get(route('workspaces.show', ['workspace' => $this->workspace, 'setup' => 1]))->assertNotFound();

        $this->actingAs($bob);
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));
        try {
            Livewire::test(CourseSetup::class, ['workspaceId' => $this->workspace]);
            $this->fail('Expected not found.');
        } catch (\Throwable $e) {
            $this->assertTrue($e instanceof NotFound || $e->getPrevious() instanceof NotFound);
        }
    }
}
