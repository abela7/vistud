<?php

namespace Tests\Feature\Engine;

use App\Engine\Context\Tokens;
use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\Settings;
use App\Engine\Tools\Context;
use App\Engine\Vistud\Overview;
use App\Engine\Vistud\VistudToolbox;
use App\Engine\VistudAi;
use App\Livewire\Study\VistudAiPage;
use App\Livewire\Workspaces\TutorChat;
use App\Models\User;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Study\Activities;
use App\Study\Modules;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\TutorMemories;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * ViStud AI (App\Engine\VistudAi): the student's assistant above all their courses, on the main dashboard. Its own
 * rules, conversations and tools, never the tutor's; a snapshot of the whole study that stays small after years; the
 * course tools with a course to name; the same chat on a page of its own.
 */
class VistudAiTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private Fake $engine;

    private string $algorithms;

    private string $physics;

    private string $week1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-10-12 09:00:00', 'UTC'));
        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        $this->engine = new Fake;
        $this->app->instance(Engine::class, $this->engine);
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->ownAiKey($this->by);
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'consent' => true]);

        $this->algorithms = app(Workspaces::class)->create($this->by, ['name' => 'Algorithm Design', 'code' => 'CS301'])->id;
        $this->physics = app(Workspaces::class)->create($this->by, ['name' => 'Physics'])->id;
        $this->week1 = app(Modules::class)->create($this->by, $this->algorithms, ['title' => 'Week 1'])->id;
        $topics = app(Topics::class);
        $search = $topics->create($this->by, $this->algorithms, 'Linear and binary search', $this->week1)->id;
        $topics->create($this->by, $this->algorithms, 'Stable marriage problem', $this->week1);
        $topics->report($this->by, $search, 'understood');
        $topics->create($this->by, $this->physics, 'Kinematics');
        app(Activities::class)->create($this->by, $this->physics, ['title' => 'Lab report 1', 'kind' => 'assignment', 'due_on' => '2026-10-15']);
        app(TutorMemories::class)->add($this->by, null, 'Learns best from a worked example before the theory.', 'tutor');
        app(TutorMemories::class)->add($this->by, $this->algorithms, 'Exam on 14 January.', 'student');
    }

    private function context(): Context
    {
        return new Context('', null, null, 'UTC', threadId: null);
    }

    public function test_encrypted_title_search_filters_before_the_limit_and_matches_unicode_and_literal_wildcards(): void
    {
        $scope = LearnerScope::of($this->by);
        $make = function ($scope, $id, $title, $at) {
            LearnerTables::insert($scope, 'engine_threads', [
                'id' => $id, 'purpose' => VistudAi::PURPOSE, 'title' => $title, 'turns' => 1,
                'model' => 'fake/tutor', 'created_at' => $at, 'updated_at' => $at,
            ]);
        };
        $make($scope, 'older-match', 'ÄPFEL 中文 %_ literal', now()->subYear());
        for ($i = 0; $i < 130; $i++) {
            $make($scope, 'newer-'.$i, 'Another conversation '.$i, now()->subMinutes($i));
        }
        $other = LearnerScope::of($this->principal($this->student()));
        $make($other, 'other-learner', 'ÄPFEL 中文 %_ literal', now());
        $ai = app(VistudAi::class);
        foreach (['äpfel', '中文', '%_'] as $search) {
            $this->assertSame(['older-match'], array_column($ai->conversations($this->by, $search), 'id'));
        }
        $this->assertSame([], $ai->conversations($this->by, 'missing'));
    }

    public function test_it_is_not_the_tutor_its_own_rules_conversation_and_tools(): void
    {
        $this->engine->will(Fake::says('Start with Stable marriage problem in Algorithm Design.', 1_000, 'fake/tutor'));
        $ai = app(VistudAi::class);
        $id = $ai->start($this->by, 'What should I study today?');
        $ai->send($this->by, $id, 'What should I study today?');

        $asked = $this->engine->last();
        $this->assertStringStartsWith('# You are ViStud AI', $asked->system);
        $this->assertStringNotContainsString("You are the student's tutor", $asked->system);
        $this->assertStringContainsString('# Their study now', $asked->system);
        $names = array_map(fn (array $t) => $t['function']['name'], $asked->tools);
        foreach (['courses', 'study_time', 'search_everything', 'memories', 'remember', 'forget_memory', 'topics', 'course_overview', 'set_topic_status'] as $tool) {
            $this->assertContains($tool, $names);
        }
        // The tutor's session tools are not ViStud AI's.
        foreach (['set_topic', 'make_flashcards', 'record_quiz', 'write_note', 'edit_note'] as $tool) {
            $this->assertNotContains($tool, $names);
        }
        // Every course tool names its course.
        $topics = collect($asked->tools)->firstWhere('function.name', 'topics');
        $this->assertContains('course', $topics['function']['parameters']['required']);

        // A conversation of its own: no session, no topic, no course; and no session is made.
        $thread = DB::table('engine_threads')->where('id', $id)->first();
        $this->assertSame([VistudAi::PURPOSE, null, null, null], [$thread->purpose, $thread->session_id, $thread->topic_id, $thread->workspace_id]);
        $this->assertStringNotContainsString('What should I study today?', $thread->title);
        $this->assertSame('What should I study today?', LearnerTables::query(LearnerScope::of($this->by), 'engine_threads')->where('id', $id)->value('title'));
        $this->assertSame(0, DB::table('study_sessions')->count());
        $this->assertSame([['role' => 'user', 'text' => 'What should I study today?'], ['role' => 'assistant', 'text' => 'Start with Stable marriage problem in Algorithm Design.']], array_map(fn ($t) => ['role' => $t['role'], 'text' => $t['text']], $ai->transcript($this->by, $id)));
    }

    public function test_the_snapshot_holds_the_whole_study_in_a_few_lines(): void
    {
        $session = app(Sessions::class)->log($this->by, $this->algorithms, ['date' => '2026-10-10', 'time' => '18:00', 'minutes' => 45], 'UTC');
        app(Workspaces::class)->create($this->by, ['name' => 'Old elective']);
        app(Workspaces::class)->archive($this->by, app(Workspaces::class)->list($this->by)[2]->id);

        $text = app(Overview::class)->build($this->by);
        $this->assertStringContainsString("## About them\nIn their words: nothing written yet.\nWhat their tutors remember about them for all courses:\n- Learns best from a worked example before the theory.\nRemembered for one course (the memories tool lists them): Algorithm Design 1.", $text);
        $this->assertStringContainsString('## Courses (2 of 2 active, most recently studied first)', $text);
        $this->assertStringContainsString('- Algorithm Design (CS301): 1 of 2 topics understood (50 %) · last studied', $text);
        $this->assertStringContainsString('45 min this week', $text);
        $this->assertStringContainsString('- Physics: 0 of 1 topics understood (0 %) · never studied in a session · next: Lab report 1 (assignment) on 15 Oct', $text);
        $this->assertStringContainsString('Not shown: 1 archived. The courses tool lists every course.', $text);
        $this->assertStringContainsString("## Coming up (next 14 days, in the courses shown)\n- Thu 15 Oct: Lab report 1 (assignment) (Physics)", $text);
        $this->assertStringContainsString('- 10 Oct, Algorithm Design, 45 min', $text);
        $this->assertNotNull($session);
    }

    public function test_after_three_years_the_snapshot_stays_small(): void
    {
        // Thirty courses, five hundred sessions, and a full set of memories.
        $workspaces = app(Workspaces::class);
        $memories = app(TutorMemories::class);
        $ids = [];
        for ($i = 1; $i <= 30; $i++) {
            $ids[] = $workspaces->create($this->by, ['name' => "Course {$i}"])->id;
        }
        foreach (array_slice($ids, 0, 12) as $id) {
            $workspaces->archive($this->by, $id);
        }
        $rows = [];
        for ($i = 0; $i < 500; $i++) {
            $at = CarbonImmutable::parse('2023-10-12 08:00:00', 'UTC')->addDays($i * 2);
            $rows[] = ['id' => (string) Str::uuid(), 'learner_id' => $this->by->learnerId, 'workspace_id' => $ids[$i % 30], 'mode' => 'topic', 'state' => 'ended', 'manual' => 1, 'started_at' => $at, 'ended_at' => $at->addHour(), 'last_activity_at' => $at->addHour(), 'study_seconds' => 3600, 'break_seconds' => 0, 'revision' => 1, 'created_at' => $at, 'updated_at' => $at];
        }
        LearnerTables::insert(LearnerScope::of($this->by), 'study_sessions', $rows);
        foreach (array_slice($ids, 12, TutorMemories::MAX_EACH) as $n => $id) {
            for ($j = 1; $j <= TutorMemories::MAX_EACH; $j++) {
                $memories->add($this->by, $id, "Line {$j} for course {$n}.", 'tutor');
            }
        }

        $text = app(Overview::class)->build($this->by);
        $courses = substr_count(substr($text, strpos($text, '## Courses'), strpos($text, '## Coming up') - strpos($text, '## Courses')), "\n- ");
        $this->assertSame(Overview::COURSES, $courses);
        $this->assertStringContainsString('Not shown: 8 more active and 12 archived.', $text);
        $this->assertSame(Overview::SESSIONS, substr_count(substr($text, strpos($text, '## Last sessions')), "\n- "));
        $this->assertLessThan(2_500, Tokens::of($text), 'The snapshot stays small however long they have studied.');
    }

    public function test_the_tools_work_across_courses_and_in_the_course_named(): void
    {
        $tools = app(VistudToolbox::class);
        $run = fn (string $name, array $input = []) => $tools->run($this->by, $this->context(), $name, $input);

        $courses = json_decode($run('courses'), true);
        $this->assertSame(['Algorithm Design', 'Physics'], array_column($courses, 'course'));
        $this->assertSame(['understood' => 1, 'total' => 2, 'need_attention' => 0, 'not_started' => 1], $courses[0]['topics']);

        $this->assertStringContainsString("In Algorithm Design:\n", $run('topics', ['course' => 'algorithm']));
        $this->assertStringContainsString('Linear and binary search', $run('topics', ['course' => 'CS301']));
        $this->assertStringNotContainsString('Kinematics', $run('topics', ['course' => 'Algorithm Design']));
        $this->assertStringContainsString('There is no course called "Biology"', $run('topics', ['course' => 'Biology']));

        $found = json_decode($run('search_everything', ['query' => 'stable']), true);
        $this->assertNotEmpty($found[0]['id']);
        $this->assertSame([['id' => $found[0]['id'], 'course' => 'Algorithm Design', 'kind' => 'topic', 'name' => 'Stable marriage problem']], $found);

        $memories = json_decode($run('memories'), true);
        $this->assertNotEmpty($memories[0]['id']);
        $this->assertSame([[1, 'all courses'], [2, 'Algorithm Design']], array_map(fn ($m) => [$m['number'], $m['for']], $memories));
        $this->assertStringContainsString('Remembered for Physics: "Prefers labs on Fridays."', $run('remember', ['note' => 'Prefers labs on Fridays.', 'course' => 'Physics']));
        $this->assertStringContainsString('in place of "Learns best from a worked example before the theory."', $run('remember', ['note' => 'Studies best in the morning.', 'replaces' => $memories[0]['id']]));
        $thread = app(VistudAi::class)->start($this->by, 'Forget something.');
        $this->assertStringContainsString('the student must agree first', $tools->run($this->by, new Context('', null, null, 'UTC', threadId: $thread), 'forget_memory', ['number' => 1]));
        $this->assertStringContainsString('the student must agree first', $tools->run($this->by, new Context('', null, null, 'UTC', threadId: $thread), 'forget_memory', ['id' => $memories[1]['id']]));

        // Over MCP, memory writes require an id and refuse list numbers.
        $mcp = $this->principal($this->ada, channel: 'mcp');
        $this->assertStringContainsString("Give the memory's id from the memories tool, not its list number.", $tools->run($mcp, $this->context(), 'remember', ['note' => 'Updated.', 'replaces' => '1']));
        $this->assertStringContainsString("Give the memory's id from the memories tool, not its list number.", $tools->run($mcp, new Context('', null, null, 'UTC', threadId: $thread), 'forget_memory', ['number' => 1]));
        $this->assertStringContainsString('the student must agree first', $tools->run($mcp, new Context('', null, null, 'UTC', threadId: $thread), 'forget_memory', ['id' => $memories[1]['id']]));

        // A write tool wrapped by InCourse requires an exact course name or id; an ambiguous partial changes nothing and lists candidates.
        $algebra = app(Workspaces::class)->create($this->by, ['name' => 'Algebra II'])->id;
        $ambiguousRead = $run('topics', ['course' => 'alg']);
        $this->assertStringContainsString("(id {$this->algorithms})", $ambiguousRead);
        $this->assertStringContainsString("(id {$algebra})", $ambiguousRead);
        $this->assertStringContainsString('There is no course called "alg"', $run('set_topic_status', ['course' => 'alg', 'topic' => 'Stable marriage problem', 'status' => 'covered', 'reason' => 'Done.']));

        app(Sessions::class)->log($this->by, $this->physics, ['date' => '2026-10-11', 'time' => '10:00', 'minutes' => 30], 'UTC');
        $time = json_decode($run('study_time', ['days' => 7]), true);
        $this->assertSame([1, 30, ['Physics' => 30]], [$time['sessions'], $time['minutes'], $time['by_course']]);
        $this->assertArrayHasKey('by_day', $time);
        $this->assertArrayHasKey('by_month', json_decode($run('study_time', ['days' => 1000]), true));
    }

    public function test_the_page_lists_opens_and_deletes_conversations_and_the_chat_is_the_same_one(): void
    {
        $this->actingAs($this->ada);
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        // The same chat, in its ViStud AI mode: its starters, no attaching, and its first words start a conversation.
        $this->engine->will(Fake::says('Physics first: Lab report 1 is due on Thursday.', 1_000, 'fake/tutor'));
        $chat = Livewire::test(TutorChat::class, ['vistud' => true])
            ->assertSee('Ask me anything about your study')->assertSee('What should I study today?')->assertDontSee('Add file, photo or note')
            ->call('say', TutorChat::VISTUD_STARTERS[0]['prompt'])
            ->assertDispatched('vistud-conversation-started')->assertSee('Physics first: Lab report 1 is due on Thursday.');
        $id = $chat->get('conversationId');
        $this->assertNotNull($id);
        // A starter it doesn't offer is not sent.
        $chat->call('say', 'Write my essay.');
        $this->assertCount(2, app(VistudAi::class)->transcript($this->by, $id));

        $page = Livewire::test(VistudAiPage::class)->assertSee('What should I study today, across all my courses?');
        $page->call('newChat')->assertSet('conversation', null)->assertSee('New chat')->call('open', $id)->assertSet('conversation', $id);
        $page->call('open', 'not-a-conversation')->assertSet('conversation', $id);
        // Found by its title; the bar names the one open; full screen and the list are the page's own.
        $page->set('search', 'study today')->assertSeeHtml('vistud-ai-title">What should I study today')
            ->set('search', 'kinematics')->assertSee('No conversation is called that.')->set('search', '')
            ->assertSeeHtml('vistud-ai-current" title="What should I study today')->assertSeeHtml('x-data="vistudAi"')->assertSee('Full screen');
        $page->call('delete', $id)->assertSet('conversation', null)->assertSee('Your conversations show here.');
        $this->assertSame(0, DB::table('engine_threads')->where('purpose', VistudAi::PURPOSE)->count());
    }

    public function test_it_is_on_the_main_dashboard_only(): void
    {
        $this->actingAs($this->ada);
        $this->get(route('vistud-ai'))->assertOk()->assertSee('ViStud AI')->assertSeeLivewire(VistudAiPage::class);
        $this->get(route('home'))->assertOk()->assertSee('Ask ViStud AI')->assertSee(route('vistud-ai'), false);
        // Inside a course, no way to it: the course has its own tutor.
        $this->get(route('workspaces.show', $this->algorithms))->assertOk()->assertDontSee(route('vistud-ai'), false);
        // Another student's conversation is not theirs to open.
        $theirs = app(VistudAi::class)->start($this->by, 'Mine.');
        $this->actingAs($other = $this->student());
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));
        Livewire::withQueryParams(['c' => $theirs])->test(VistudAiPage::class)->assertSet('conversation', null);
    }
}
