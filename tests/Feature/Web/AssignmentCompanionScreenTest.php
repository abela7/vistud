<?php

namespace Tests\Feature\Web;

use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\Settings;
use App\Livewire\Workspaces\AssignmentCompanion;
use App\Livewire\Workspaces\TutorChat;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Study\Activities;
use App\Study\ActivityDetails;
use App\Study\AssignmentLog;
use App\Study\AssignmentSources;
use App\Study\AssignmentUnderstandings;
use App\Study\Modules;
use App\Study\Workspaces;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\MakesStudyFiles;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

class AssignmentCompanionScreenTest extends TestCase
{
    use CreatesAccounts, MakesStudyFiles, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private string $workspaceId;

    private string $moduleId;

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
        app(Settings::class)->set($this->by, [
            'tutor_model' => 'fake/tutor',
            'reader_model' => 'fake/quick',
            'consent' => true,
            'auto_read_files' => false,
        ]);

        $this->workspaceId = app(Workspaces::class)->create($this->by, ['name' => 'Computer Science'])->id;
        $this->moduleId = app(Modules::class)->create($this->by, $this->workspaceId, ['title' => 'Databases'])->id;
        $this->assignment = app(Activities::class)->create($this->by, $this->workspaceId, [
            'title' => 'Coursework 1: Relational Schema',
            'kind' => 'assignment',
            'module_id' => $this->moduleId,
            'due_on' => '2026-10-25',
            'due_time' => '17:00',
        ]);
    }

    public function test_route_renders_with_header_chat_and_rail_sections(): void
    {
        $this->actingAs($this->ada);

        $response = $this->get(route('workspaces.assignments.work', [$this->workspaceId, $this->assignment->id]));
        $response->assertOk();
        $response->assertSee('Coursework 1: Relational Schema');
        $response->assertSee('Assignment');
        $response->assertSee(route('workspaces.assignments.show', [$this->workspaceId, $this->assignment->id]));
        $response->assertSee('Stage');
        $response->assertSee('The picture');
        $response->assertSee('Plan');
        $response->assertSee('Log');
    }

    public function test_route_returns_404_for_another_student_or_wrong_workspace(): void
    {
        $other = $this->student();
        $this->actingAs($other);

        $this->get(route('workspaces.assignments.work', [$this->workspaceId, $this->assignment->id]))
            ->assertNotFound();

        $this->actingAs($this->ada);
        $otherWorkspace = app(Workspaces::class)->create($this->by, ['name' => 'Maths'])->id;

        $this->get(route('workspaces.assignments.work', [$otherWorkspace, $this->assignment->id]))
            ->assertNotFound();
    }

    public function test_starters_render_and_can_be_sent(): void
    {
        $this->actingAs($this->ada);

        $chat = Livewire::test(TutorChat::class, [
            'workspaceId' => $this->workspaceId,
            'activityId' => $this->assignment->id,
        ]);

        $chat->assertSee('Explain the assignment to me');
        $chat->assertSee('What will the teacher look for?');

        $this->engine->will(Fake::says('Here is an explanation of the assignment.'));

        $chat->call('say', 'Explain the assignment to me');
        $chat->assertSee('Here is an explanation of the assignment.');
    }

    public function test_stage_stepper_sets_the_stage(): void
    {
        $this->actingAs($this->ada);

        $companion = Livewire::test(AssignmentCompanion::class, [
            'workspaceId' => $this->workspaceId,
            'activityId' => $this->assignment->id,
        ]);

        $companion->call('setStage', 'plan');
        $this->assertSame('plan', app(Activities::class)->find($this->by, $this->assignment->id)->stage);

        $companion->call('setStage', 'do');
        $this->assertSame('do', app(Activities::class)->find($this->by, $this->assignment->id)->stage);
    }

    public function test_log_add_and_delete(): void
    {
        $this->actingAs($this->ada);

        $companion = Livewire::test(AssignmentCompanion::class, [
            'workspaceId' => $this->workspaceId,
            'activityId' => $this->assignment->id,
        ]);

        $companion->set('newLogText', 'Decided to use PostgreSQL.')
            ->set('newLogKind', 'decision')
            ->call('addLog')
            ->assertHasNoErrors()
            ->assertSee('Decided to use PostgreSQL.');

        $logs = app(AssignmentLog::class)->list($this->by, $this->assignment->id);
        $this->assertCount(1, $logs);
        $this->assertSame('Decided to use PostgreSQL.', $logs[0]->text);
        $this->assertSame('student', $logs[0]->by);

        $companion->call('deleteLog', $logs[0]->id)
            ->assertDontSee('Decided to use PostgreSQL.');

        $this->assertCount(0, app(AssignmentLog::class)->list($this->by, $this->assignment->id));
    }

    public function test_read_again_starts_and_finishes_understanding_job(): void
    {
        $this->actingAs($this->ada);

        app(AssignmentSources::class)->add($this->by, $this->assignment->id, [
            'kind' => 'brief',
            'title' => 'Brief',
            'text' => 'Coursework instructions.',
        ]);

        $answer = json_encode([
            'summary' => 'Airline schema design in 3NF.',
            'goal' => 'Create a normalized relational schema.',
            'confidence' => 'high',
            'requirements' => [['text' => 'Must normalize to 3NF', 'source' => 'S1']],
            'questions' => [['text' => 'Do we include views?']],
        ]);
        $this->engine->will(Fake::says($answer));

        $companion = Livewire::test(AssignmentCompanion::class, [
            'workspaceId' => $this->workspaceId,
            'activityId' => $this->assignment->id,
        ]);

        $companion->call('understand');
        $this->assertNull($companion->get('jobId'));

        $latest = app(AssignmentUnderstandings::class)->latest($this->by, $this->assignment->id);
        $this->assertNotNull($latest);
        $this->assertSame('Airline schema design in 3NF.', $latest->text('summary'));
    }

    public function test_assignment_page_has_work_on_it_with_the_tutor_button(): void
    {
        $this->actingAs($this->ada);

        $response = $this->get(route('workspaces.assignments.show', [$this->workspaceId, $this->assignment->id]));
        $response->assertOk();
        $response->assertSee('Work on it with the tutor');
        $response->assertSee(route('workspaces.assignments.work', [$this->workspaceId, $this->assignment->id]));
    }
}
