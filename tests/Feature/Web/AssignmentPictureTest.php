<?php

namespace Tests\Feature\Web;

use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\Settings;
use App\Livewire\Workspaces\AssignmentPicture;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Study\Activities;
use App\Study\ActivityDetails;
use App\Study\AssignmentSources;
use App\Study\AssignmentUnderstandings;
use App\Study\Files;
use App\Study\Workspaces;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\MakesStudyFiles;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** An assignment's full picture on its page: what the student gives, and what the AI understands from it. */
class AssignmentPictureTest extends TestCase
{
    use CreatesAccounts, MakesStudyFiles, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private string $workspace;

    private ActivityDetails $assignment;

    private Fake $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->engine = new Fake;
        $this->app->instance(Engine::class, $this->engine);
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'consent' => true, 'auto_read_files' => false]);
        $this->workspace = app(Workspaces::class)->create($this->by, ['name' => 'Databases'])->id;
        $this->assignment = app(Activities::class)->create($this->by, $this->workspace, ['kind' => 'assignment', 'title' => 'Joins report']);
    }

    private function picture()
    {
        $this->actingAs($this->ada);

        return Livewire::test(AssignmentPicture::class, ['workspaceId' => $this->workspace, 'activityId' => $this->assignment->id]);
    }

    private function answer(array $with = []): string
    {
        return json_encode($with + [
            'summary' => 'A report comparing inner and outer joins.',
            'goal' => 'Show when each join is the right one.',
            'requirements' => [['text' => 'Compare inner and outer joins', 'source' => 'S1'], ['text' => 'Use examples from your own database', 'source' => 'inferred']],
            'questions' => [['text' => 'Is a cover page needed?', 'source' => 'inferred']],
            'confidence' => 'medium',
        ]);
    }

    public function test_the_student_gives_what_they_have_and_the_ai_understands_it(): void
    {
        $page = $this->picture()->assertSee('The full picture')->assertSee('Give the AI everything you have');

        // A brief kept: the AI reads it at once, and says what it understands, with where each point came from.
        $this->engine->will(Fake::says($this->answer(['changes' => 'First reading.'])));
        $page->call('add', 'brief')->set('text', 'Compare inner and outer joins in a report.')->call('save')->assertHasNoErrors()
            ->assertSee('Compare inner and outer joins in a report.')
            ->assertSeeInOrder(['What the AI understands', 'Ask your teacher', 'Is a cover page needed?', 'Must do', 'Compare inner and outer joins', 'from Brief', 'Use examples from your own database', 'the AI&#039;s reading'], false)
            ->assertSee('Version 1');

        // Said right.
        $page->call('confirm')->assertSee("You said it's right", false);

        // A correction is a source of its own, and the understanding is read again from it.
        $this->engine->will(Fake::says($this->answer(['changes' => 'The report is 2,000 words, from your correction.'])));
        $page->set('correcting', true)->set('correction', 'It is 2,000 words.')->call('correct')->assertHasNoErrors()
            ->assertSee('Your correction')->assertSee('Version 2')->assertSee('The report is 2,000 words, from your correction.');
        $this->assertSame(2, app(AssignmentUnderstandings::class)->latest($this->by, $this->assignment->id)->revision);

        // A file added to its folder: the understanding says it is out of date, until it is read again.
        app(Files::class)->upload($this->by, 'folder', (string) $this->assignment->folderId, $this->temp("Analysis 40%.\n"), 'Rubric.txt');
        $page->dispatch('assignment-files-changed')->assertSee('Rubric.txt')->assertSee('Out of date');
        $this->engine->will(Fake::says($this->answer(['changes' => 'The rubric is read.'])));
        $page->call('understand')->assertDontSee('Out of date')->assertSee('Version 3');
    }

    public function test_a_source_is_changed_and_deleted_in_place(): void
    {
        $ans = $this->answer(['requirements' => []]);
        $this->engine->will(Fake::says($ans), Fake::says($ans), Fake::says($ans));
        $page = $this->picture()->call('add', 'email')->set('title', 'Deadline moved')->set('receivedOn', '2026-10-06')->set('text', 'Now due on the 24th.')->call('save')
            ->assertSee('Deadline moved')->assertSee('6 Oct');
        $source = app(AssignmentSources::class)->list($this->by, $this->assignment->id)[0];

        $page->call('edit', $source->id)->assertSet('title', 'Deadline moved')->assertSet('kind', 'email')
            ->set('title', 'Deadline moved again')->call('save')->assertSee('Deadline moved again');
        $page->call('delete', $source->id)->assertDontSee('Deadline moved again');
        $this->assertSame([], app(AssignmentSources::class)->list($this->by, $this->assignment->id));
    }

    public function test_refusals_and_a_missing_setup_are_said_plainly(): void
    {
        $page = $this->picture()->call('add', 'brief')->set('text', '   ')->call('save')->assertHasErrors('text');
        $page->call('cancel')->call('understand')->assertSee('there is nothing to understand yet');

        app(Settings::class)->set($this->by, ['consent' => false]);
        $page->call('add', 'brief')->set('text', 'Compare inner and outer joins.')->call('save')
            ->assertSee('Set up your AI first');
        $this->assertSame([], $this->engine->requests);
    }

    public function test_another_students_assignment_is_not_shown(): void
    {
        $grace = $this->student();
        $this->actingAs($grace);

        $this->assertThrows(fn () => Livewire::test(AssignmentPicture::class, ['workspaceId' => $this->workspace, 'activityId' => $this->assignment->id]));
    }
}
