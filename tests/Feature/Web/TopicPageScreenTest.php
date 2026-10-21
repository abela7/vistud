<?php

namespace Tests\Feature\Web;

use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\Settings;
use App\Livewire\Workspaces\TopicPage;
use App\Livewire\Workspaces\TutorChat;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Study\Findings;
use App\Study\Folders;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\Questions;
use App\Study\Topics;
use App\Study\Workspaces;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * Dedicated Topic Page test (App\Livewire\Workspaces\TopicPage):
 * Deep dive topic features:
 * 1. Watch progress and update status
 * 2. Add questions specifically on the topic
 * 3. AI Tutor scoped to topic
 * 4. Key points, rename, move, and remove
 * 5. Notes & files in topic context
 */
class TopicPageScreenTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private string $workspace;

    private string $module;

    private string $folder;

    private string $topic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);

        $this->workspace = app(Workspaces::class)->create($this->by, ['name' => 'Operating Systems'])->id;
        $this->module = app(Modules::class)->create($this->by, $this->workspace, ['title' => 'Concurrency'])->id;
        $this->folder = app(Folders::class)->create($this->by, 'module', $this->module, 'Deadlocks Lab')->id;
        $this->topic = app(Topics::class)->create($this->by, $this->workspace, 'Coffman Conditions', $this->module, $this->folder)->id;
    }

    public function test_the_topic_page_route_renders_successfully(): void
    {
        $this->actingAs($this->ada);
        $this->get(route('workspaces.topics.show', [$this->workspace, $this->topic]))
            ->assertOk()
            ->assertSee('Coffman Conditions')
            ->assertSee('Where You Stand')
            ->assertSee('Not understood');
    }

    public function test_another_learner_cannot_view_the_topic_page(): void
    {
        $other = $this->student();
        $this->actingAs($other);
        $this->get(route('workspaces.topics.show', [$this->workspace, $this->topic]))
            ->assertNotFound();
    }

    public function test_status_can_be_updated_including_not_understand_and_not_started(): void
    {
        $this->actingAs($this->ada);
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        $page = Livewire::test(TopicPage::class, ['workspaceId' => $this->workspace, 'topicId' => $this->topic]);

        // Mark as Not understood (confused)
        $page->call('report', 'confused')
            ->assertSee('Status marked as Not understood.')
            ->assertDispatched('topics-changed');
        $this->assertSame('confused', app(Topics::class)->find($this->by, $this->topic)->status);

        // Mark as Studying (covered)
        $page->call('report', 'covered')
            ->assertSee('Status marked as Studying.');
        $this->assertSame('covered', app(Topics::class)->find($this->by, $this->topic)->status);

        // Mark as Understood
        $page->call('report', 'understood')
            ->assertSee('Status marked as Understood.');
        $this->assertSame('understood', app(Topics::class)->find($this->by, $this->topic)->status);

        // Reset to Not started
        $page->call('report', 'not_started')
            ->assertSee('Status marked as Not started.');
        // "Not started" is kept as the student's word, so it holds over what was met before (TrackingTest).
        $back = app(Topics::class)->find($this->by, $this->topic);
        $this->assertSame(['not_started', 'not_started'], [$back->status, $back->shown()]);
    }

    public function test_questions_can_be_asked_and_answered_specifically_on_this_topic(): void
    {
        $this->actingAs($this->ada);
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        $page = Livewire::test(TopicPage::class, ['workspaceId' => $this->workspace, 'topicId' => $this->topic]);

        // Ask question
        $page->set('newQuestionText', 'Why is circular wait necessary for a deadlock?')
            ->call('askQuestion')
            ->assertSee('Question added to this topic.')
            ->assertSet('newQuestionText', '');

        $questions = app(Questions::class)->list($this->by, $this->workspace);
        $topicQuestions = array_filter($questions, fn ($q) => $q->topicId === $this->topic);
        $this->assertCount(1, $topicQuestions);
        $q = array_values($topicQuestions)[0];
        $this->assertSame('Why is circular wait necessary for a deadlock?', $q->text);
        $this->assertSame('pending', $q->status);

        // Answer question
        $page->call('startAnswering', $q->id)
            ->set('answeringText', 'Because processes would otherwise finish without mutual block.')
            ->call('saveAnswer', $q->id)
            ->assertSee('Question answered and marked understood.');

        $updatedQ = app(Questions::class)->find($this->by, $q->id);
        $this->assertSame('answered', $updatedQ->status);
        $this->assertSame('Because processes would otherwise finish without mutual block.', $updatedQ->answer);
    }

    public function test_key_points_can_be_added_and_removed(): void
    {
        $this->actingAs($this->ada);
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        $page = Livewire::test(TopicPage::class, ['workspaceId' => $this->workspace, 'topicId' => $this->topic]);

        $page->set('tab', 'settings')
            ->set('newPoint', 'Must have mutual exclusion, hold and wait, no preemption, circular wait.')
            ->call('addPoint')
            ->assertSee('Key point added.')
            ->assertSet('newPoint', '');

        $points = app(Findings::class)->byTopic($this->by, $this->workspace)[$this->topic];
        $this->assertCount(1, $points);
        $this->assertSame('Must have mutual exclusion, hold and wait, no preemption, circular wait.', $points[0]->text);

        $page->call('removePoint', $points[0]->id)
            ->assertSee('Key point removed.');
        $this->assertSame([], app(Findings::class)->byTopic($this->by, $this->workspace)[$this->topic] ?? []);
    }

    public function test_topic_can_be_renamed_and_moved(): void
    {
        $this->actingAs($this->ada);
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        $page = Livewire::test(TopicPage::class, ['workspaceId' => $this->workspace, 'topicId' => $this->topic]);

        // Rename
        $page->set('name', 'The 4 Coffman Conditions')->call('rename')->assertSee('Topic renamed.');
        $this->assertSame('The 4 Coffman Conditions', app(Topics::class)->find($this->by, $this->topic)->name);

        // Move to module level without folder
        $page->set('place', "module:{$this->module}")->call('move')->assertSee('Topic moved.');
        $this->assertNull(app(Topics::class)->find($this->by, $this->topic)->folderId);
        $this->assertSame($this->module, app(Topics::class)->find($this->by, $this->topic)->moduleId);
    }

    public function test_topic_can_be_retired_with_confirmation(): void
    {
        $this->actingAs($this->ada);
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        $page = Livewire::test(TopicPage::class, ['workspaceId' => $this->workspace, 'topicId' => $this->topic]);

        $page->call('askRemove')->assertSet('confirmingRemove', true)
            ->call('cancelRemove')->assertSet('confirmingRemove', false)
            ->call('askRemove')->call('removeTopic')
            ->assertRedirect();

        $this->assertSame([], app(Topics::class)->list($this->by, $this->workspace));
    }

    public function test_the_ai_tab_is_the_same_tutor_chat_kept_on_the_topic_and_not_a_session(): void
    {
        $engine = new Fake;
        $this->app->instance(Engine::class, $engine);
        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        $this->ownAiKey($this->by);
        app(Settings::class)->set($this->by, [
            'tutor_model' => 'fake/tutor',
            'reader_model' => 'fake/plain',
            'helper_model' => 'fake/tutor',
            'consent' => true,
        ]);
        $this->actingAs($this->ada);
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        Livewire::test(TopicPage::class, ['workspaceId' => $this->workspace, 'topicId' => $this->topic])
            ->assertSeeHtml('wire:name="workspaces.tutor-chat"');

        $chat = Livewire::test(TutorChat::class, ['workspaceId' => $this->workspace, 'topicId' => $this->topic])
            ->assertSee('Ask anything about Coffman Conditions', false);

        $engine->will(Fake::says('Deadlock prevention breaks one of the four Coffman conditions.', 1_000, 'fake/tutor'));
        $chat->call('send', 'How do we break the conditions?')->assertSet('text', '')->assertSet('error', null)
            ->assertSee('Deadlock prevention breaks one of the four Coffman conditions.');

        $asked = $engine->last();
        $this->assertSame('fake/tutor', $asked->model);
        $this->assertStringContainsString('## This chat', $asked->system);
        $this->assertStringContainsString('Coffman Conditions', $asked->system);
        $tools = array_map(fn (array $tool) => $tool['function']['name'] ?? $tool['name'] ?? '', $asked->tools);
        $this->assertContains('set_topic_status', $tools);
        $this->assertNotContains('end_session', $tools);

        // It remembers the talk: the next turn carries the first one.
        $engine->will(Fake::says('Hold-and-wait is the easiest one to break.', 1_000, 'fake/tutor'));
        $chat->call('send', 'Which is easiest?');
        $this->assertCount(3, $engine->last()->messages);
        $this->assertSame('How do we break the conditions?', $engine->last()->messages[0]['content']);

        // Talking about a topic isn't a study session and isn't counted as studying it.
        $this->assertSame(0, DB::table('study_sessions')->count());
        $this->assertNull(app(Topics::class)->find($this->by, $this->topic)->status);

        $chat->call('startOver')->assertDontSee('Hold-and-wait is the easiest one to break.')
            ->assertSee('Ask anything about Coffman Conditions', false);
    }
}
