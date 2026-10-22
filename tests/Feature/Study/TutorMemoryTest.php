<?php

namespace Tests\Feature\Study;

use App\Engine\ChatMarks;
use App\Engine\Context\Stack;
use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\SessionChat;
use App\Engine\Settings;
use App\Engine\Toolbox;
use App\Engine\Tools\Context;
use App\Livewire\Study\Memories;
use App\Livewire\Workspaces\Instructions;
use App\Livewire\Workspaces\TutorChat;
use App\Models\User;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\Briefings;
use App\Study\Capture;
use App\Study\Modules;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\TutorMemories;
use App\Study\TutorMemory;
use App\Study\Workspaces;
use App\Study\WriteBack;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * What the tutor remembers about its student (App\Study\TutorMemories): kept by the tutor with `remember` or written by
 * the student, for a course or for all, shown to the tutor numbered at the start of every chat, and the student's to
 * see, change, delete and turn off.
 */
class TutorMemoryTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private TutorMemories $memories;

    private string $algorithms;

    private string $physics;

    private string $module;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-10-12 09:00:00', 'UTC'));
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->memories = app(TutorMemories::class);
        $this->algorithms = app(Workspaces::class)->create($this->by, ['name' => 'Algorithm Design'])->id;
        $this->physics = app(Workspaces::class)->create($this->by, ['name' => 'Physics'])->id;
        $this->module = app(Modules::class)->create($this->by, $this->algorithms, ['title' => 'Week 1'])->id;
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'consent' => true]);
    }

    /** @return list<string> */
    private function texts(?string $workspaceId): array
    {
        return array_map(fn (TutorMemory $m) => $m->text, $this->memories->list($this->by, $workspaceId));
    }

    private function refused(callable $do, string $words): void
    {
        try {
            $do();
            $this->fail('Expected it to be refused.');
        } catch (Unprocessable $e) {
            $this->assertStringContainsString($words, $e->details['fields']['text'][0]);
        }
    }

    public function test_a_course_sees_what_is_for_all_courses_first_then_its_own_and_never_another_courses(): void
    {
        $this->memories->add($this->by, $this->algorithms, 'Wants worked examples before proofs.', 'tutor');
        $this->travel(1)->minutes();
        $this->memories->add($this->by, null, "  Anxious about maths;\n build from the basics. ", 'tutor');
        $this->memories->add($this->by, $this->physics, 'Lab report due Friday.', 'student');

        // One line each, spaces folded; for all courses first.
        $this->assertSame(['Anxious about maths; build from the basics.', 'Wants worked examples before proofs.'], $this->texts($this->algorithms));
        $this->assertSame(['Anxious about maths; build from the basics.', 'Lab report due Friday.'], $this->texts($this->physics));
        $this->assertSame(['Anxious about maths; build from the basics.'], $this->texts(null));
        $first = $this->memories->list($this->by, $this->algorithms)[0];
        $this->assertSame([true, 'tutor', 'The tutor, 12 Oct'], [$first->everywhere(), $first->by, $first->source()]);
    }

    public function test_a_line_is_short_new_and_there_is_room_for_it_or_it_replaces_one(): void
    {
        $this->refused(fn () => $this->memories->add($this->by, $this->algorithms, '   ', 'tutor'), 'Write what to remember');
        $this->refused(fn () => $this->memories->add($this->by, $this->algorithms, str_repeat('a', TutorMemories::MAX_TEXT + 1), 'tutor'), 'one short line');
        $this->memories->add($this->by, $this->algorithms, 'Likes diagrams.', 'tutor');
        $this->refused(fn () => $this->memories->add($this->by, $this->algorithms, 'likes  DIAGRAMS.', 'tutor'), 'remembered already');

        for ($i = 2; $i <= TutorMemories::MAX_EACH; $i++) {
            $this->memories->add($this->by, $this->algorithms, "Line {$i}.", 'tutor');
        }
        $this->refused(fn () => $this->memories->add($this->by, $this->algorithms, 'One too many.', 'tutor'), 'no room');
        // Full here is not full everywhere: all courses and other courses have their own room.
        $this->memories->add($this->by, null, 'For every course.', 'tutor');
        $this->memories->add($this->by, $this->physics, 'For physics.', 'tutor');

        // Replacing one in a full course takes its place.
        $old = $this->memories->list($this->by, $this->algorithms)[1];
        $new = $this->memories->add($this->by, $this->algorithms, 'Prefers diagrams to text.', 'tutor', replacesId: $old->id);
        $this->assertSame($old->id, $new->id);
        $this->assertContains('Prefers diagrams to text.', $this->texts($this->algorithms));
        $this->assertNotContains('Likes diagrams.', $this->texts($this->algorithms));
        $this->assertCount(TutorMemories::MAX_EACH + 1, $this->memories->list($this->by, $this->algorithms));
    }

    public function test_the_student_changes_moves_and_deletes_a_line_and_a_deleted_course_takes_its_own(): void
    {
        $line = $this->memories->add($this->by, $this->algorithms, 'Scared of maths.', 'tutor');
        $changed = $this->memories->update($this->by, $line->id, 'Scared of maths, getting better.', null);
        $this->assertSame(['Scared of maths, getting better.', true, 'student'], [$changed->text, $changed->everywhere(), $changed->by]);

        $kept = $this->memories->add($this->by, $this->algorithms, 'Exam on 14 January.', 'student');
        $this->memories->forget($this->by, $kept->id);
        $this->assertSame(['Scared of maths, getting better.'], $this->texts($this->algorithms));

        $this->memories->add($this->by, $this->algorithms, 'Week 1 went well.', 'tutor');
        app(Workspaces::class)->delete($this->by, $this->algorithms);
        $this->assertSame(['Scared of maths, getting better.'], $this->texts(null));
        $this->assertSame(1, DB::table('tutor_memories')->count());
    }

    public function test_another_student_cannot_see_change_or_delete_them(): void
    {
        $line = $this->memories->add($this->by, null, 'Scared of maths.', 'tutor');
        $other = $this->principal($this->student());
        $this->assertSame([], $this->memories->list($other, null));
        foreach ([fn () => $this->memories->forget($other, $line->id), fn () => $this->memories->update($other, $line->id, 'Mine now.', null), fn () => $this->memories->add($other, $this->algorithms, 'In Ada\'s course.', 'tutor')] as $try) {
            try {
                $try();
                $this->fail('Expected not found.');
            } catch (NotFound) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(['Scared of maths.'], $this->texts(null));
    }

    public function test_the_tutor_remembers_with_its_tool_replaces_by_number_and_keeps_nothing_when_it_is_off(): void
    {
        $session = app(Sessions::class)->start($this->by, $this->algorithms, null, $this->module);
        $context = new Context($this->algorithms, $this->module, $session->id, 'UTC');
        $run = fn (array $input) => app(Toolbox::class)->run($this->by, $context, 'remember', $input);

        $this->assertStringContainsString('Remembered for all courses: "Anxious about maths; build from the basics."', $run(['note' => 'Anxious about maths; build from the basics.', 'all_courses' => true]));
        $this->assertStringContainsString('Remembered for this course', $run(['note' => 'Mixes up O(n) and O(log n).']));
        $row = LearnerTables::query(LearnerScope::of($this->by), 'tutor_memories')->get()->first(fn ($memory) => $memory->text === 'Mixes up O(n) and O(log n).');
        $this->assertSame(['tutor', $session->id, $this->algorithms], [$row->by, $row->session_id, $row->workspace_id]);
        $this->assertSame(['Anxious about maths; build from the basics.', 'Mixes up O(n) and O(log n).'], array_column($context->effects->take()['remembered'], 'text'));

        // Number 2 in its list is replaced; a number it doesn't have is said.
        $this->assertStringContainsString('in place of "Mixes up O(n) and O(log n)."', $run(['note' => 'Now tells O(n) from O(log n).', 'replaces' => 2]));
        $this->assertStringContainsString('There is no line 7', $run(['note' => 'Anything.', 'replaces' => 7]));
        $this->assertStringContainsString('Not kept: That is remembered already.', $run(['note' => 'Now tells O(n) from O(log n).']));

        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'consent' => true, 'tutor_remembers' => false]);
        $this->assertStringContainsString('Not kept: the student has turned off', $run(['note' => 'Likes mornings.']));
        $this->assertSame(['Anxious about maths; build from the basics.', 'Now tells O(n) from O(log n).'], $this->texts($this->algorithms));
    }

    public function test_the_tutor_reads_what_it_remembers_numbered_at_the_start_of_every_chat(): void
    {
        $session = app(Sessions::class)->start($this->by, $this->algorithms, null, $this->module);
        $system = fn () => app(Stack::class)->build($this->by, app(Sessions::class)->find($this->by, $session->id), null, true)->system;
        $this->assertStringContainsString('What you remember about them: nothing yet. Keep what lasts with remember.', $system());

        $this->memories->add($this->by, $this->algorithms, 'Wants worked examples first.', 'student');
        $this->memories->add($this->by, null, 'Anxious about maths.', 'tutor');
        $this->assertStringContainsString("What you remember about them (kept from earlier chats, or written by them; they see, change and delete it):\n1. Anxious about maths. (all courses)\n2. Wants worked examples first.", $system());

        // Turned off: nothing is shown, and the tutor is told to keep nothing.
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'consent' => true, 'tutor_remembers' => false]);
        $off = $system();
        $this->assertStringContainsString('They have turned off your remembering things about them: keep nothing with remember.', $off);
        $this->assertStringNotContainsString('Anxious about maths.', $off);
    }

    public function test_the_chat_shows_what_was_remembered_with_forget_in_a_session_and_on_a_topic(): void
    {
        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        $this->ownAiKey($this->by);
        $engine = new Fake;
        $this->app->instance(Engine::class, $engine);
        $this->actingAs($this->ada);
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));
        $session = app(Sessions::class)->start($this->by, $this->algorithms, null, $this->module);

        $engine->will(Fake::calls('remember', ['note' => 'Anxious about maths; go step by step.', 'all_courses' => true], 'c1'), Fake::says('Noted. We will take it one step at a time.'));
        $chat = Livewire::test(TutorChat::class, ['workspaceId' => $this->algorithms, 'sessionId' => $session->id])
            ->set('text', 'Honestly, maths scares me.')->call('send')
            ->assertSee('Remembered: Anxious about maths; go step by step.')->assertSee('Forget')->assertSeeHtml("Livewire.dispatch('memories-open', { id: ");
        $id = $this->memories->list($this->by, null)[0]->id;

        $chat->call('forgetMemory', $id)->assertSee('Forgotten: Anxious about maths; go step by step.')->assertDontSee('Remembered: Anxious');
        $this->assertSame([], $this->texts(null));

        // A topic's chat may remember too: it is about the student, wherever they are.
        $this->assertContains('remember', SessionChat::TOPIC_TOOLS);
        $topic = app(Topics::class)->create($this->by, $this->algorithms, 'Big-O notation', $this->module);
        $engine->will(Fake::calls('remember', ['note' => 'Big-O clicks with real timings.'], 'c2'), Fake::says('Good to know.'));
        Livewire::test(TutorChat::class, ['workspaceId' => $this->algorithms, 'topicId' => $topic->id])
            ->call('send', 'Timing real code made it click.')->assertSee('Remembered: Big-O clicks with real timings.');
        $this->assertSame($topic->id, DB::table('tutor_memories')->value('topic_id'));
    }

    public function test_the_student_sees_adds_changes_and_deletes_them_in_one_window_from_anywhere(): void
    {
        $this->actingAs($this->ada);
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));
        $this->memories->add($this->by, null, 'Anxious about maths.', 'tutor');
        $physics = $this->memories->add($this->by, $this->physics, 'Lab report due Friday.', 'student');

        // Closed, it shows nothing; opened from a chip, that line is marked. All courses first, then this course, then the others.
        $window = Livewire::test(Memories::class, ['workspaceId' => $this->algorithms])->assertDontSee('Anxious about maths.')
            ->dispatch('memories-open', id: $physics->id)->assertSet('open', true)->assertSet('highlight', $physics->id)->assertDispatched('memories-dialog-open')
            ->assertSeeInOrder(['What your tutor remembers', 'All your courses', 'Anxious about maths.', 'The tutor, 12 Oct', 'Physics', 'Lab report due Friday.', 'You, 12 Oct'])
            ->assertSeeHtml('memory-item is-highlighted');

        // Added for this course (or all of them), and refused when it is a repeat.
        $window->set('newText', 'Exam on 14 January.')->call('add')->assertHasNoErrors()->assertSet('newText', '')->assertDispatched('memories-changed')
            ->assertSeeInOrder(['All your courses', 'Anxious about maths.', 'Algorithm Design', 'this course', 'Exam on 14 January.', 'Physics']);
        $window->set('newText', 'anxious about MATHS.')->set('newEverywhere', true)->call('add')->assertHasErrors(['newText'])->assertSee('That is remembered already.');

        // Changed: the student's words now; and deleted.
        $line = $this->memories->list($this->by, $this->algorithms)[0];
        $window->set('newText', '')->call('edit', $line->id)->assertSet('editText', 'Anxious about maths.')->assertSet('editEverywhere', true)
            ->set('editText', 'Less anxious about maths now.')->call('save')->assertSet('editId', null)->assertSee('Less anxious about maths now.');
        $this->assertSame('student', $this->memories->find($this->by, $line->id)->by);
        $window->call('forget', $line->id)->assertDontSee('Less anxious about maths now.');
        $this->assertSame(['Exam on 14 January.'], $this->texts($this->algorithms));

        // From Settings (no course), a new line is for every course; another course's stays in its course when changed.
        $settings = Livewire::test(Memories::class)->dispatch('memories-open')->assertSee('For all your courses.')
            ->set('newText', 'Prefers short sessions.')->call('add')->assertHasNoErrors();
        $this->assertTrue($this->memories->list($this->by, null)[0]->everywhere());
        $settings->call('edit', $physics->id)->set('editText', 'Lab report due Monday.')->call('save');
        $this->assertSame([$this->physics, 'Lab report due Monday.'], [$this->memories->find($this->by, $physics->id)->workspaceId, $this->memories->find($this->by, $physics->id)->text]);

        // Turned off, it says so and where to turn it back on.
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'consent' => true, 'tutor_remembers' => false]);
        $window->dispatch('memories-open')->assertSee('Remembering is off');
    }

    public function test_the_ways_in_a_chats_chip_a_courses_instructions_and_settings(): void
    {
        $this->actingAs($this->ada);
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));
        $this->memories->add($this->by, $this->algorithms, 'Exam on 14 January.', 'student');

        Livewire::test(Instructions::class, ['workspaceId' => $this->algorithms])->call('edit')
            ->assertSee('What your tutor remembers')->assertSee('1 note. See, change or add.')->assertSeeHtml("Livewire.dispatch('memories-open')");
        $this->get(route('settings', ['part' => 'ai']))->assertOk()->assertSee('See what it remembers')->assertSeeLivewire(Memories::class);
        $this->get(route('workspaces.show', $this->algorithms))->assertOk()->assertSeeLivewire(Memories::class);
    }

    public function test_a_tutor_without_tools_marks_what_to_remember_and_the_student_ticks_it_to_keep_it(): void
    {
        $text = "Let's go slowly.\n\n<remember for=\"all\">Learns best from a worked example before the theory.</remember>\n<remember>Exam on 14 January.</remember>";
        $marks = Capture::parse($text);
        $this->assertSame([['remember', 'Learns best from a worked example before the theory.', true], ['remember', 'Exam on 14 January.', false]], array_map(fn ($m) => [$m['kind'], $m['text'], $m['everywhere']], $marks));
        // In the chat it reads as a labelled quote, not a tag.
        $this->assertStringContainsString("> **To remember about you**\n> Learns best from a worked example before the theory. *(all courses)*", ChatMarks::present($text));

        // In the review: never ticked for them, and no topic to choose.
        $session = app(Sessions::class)->start($this->by, $this->algorithms, null, $this->module);
        $review = app(WriteBack::class)->review($this->by, $session->id, $text);
        $this->assertSame([false, false], array_column($review, 'include'));
        $this->assertArrayNotHasKey('topic_id', $review[0]);

        // Ticked, the first is kept for every course, as the tutor's, from this session; the unticked one is not.
        $review[0]['include'] = true;
        $result = app(WriteBack::class)->apply($this->by, $session->id, $review, false);
        $this->assertSame(['remember' => 1], $result['saved']);
        $kept = $this->memories->list($this->by, $this->algorithms);
        $this->assertSame([['Learns best from a worked example before the theory.', true, 'tutor', $session->id]], array_map(fn ($m) => [$m->text, $m->everywhere(), $m->by, $m->sessionId], $kept));

        // Turned off, the marks aren't offered at all.
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'consent' => true, 'tutor_remembers' => false]);
        $this->assertSame([], array_values(array_filter(app(WriteBack::class)->review($this->by, $session->id, $text), fn ($i) => $i['kind'] === 'remember')));
    }

    public function test_the_rules_for_a_tutor_without_tools_and_for_another_ai_say_how_to_mark_it(): void
    {
        $this->assertStringContainsString('<remember>One short line.</remember>', Stack::rules(false));
        $this->assertStringNotContainsString('<remember>', Stack::rules(true));
        $this->assertStringContainsString('<remember>One short line, at most 160 characters.</remember>', (string) file_get_contents(base_path('resources/prompts/tutor.md')));
    }

    public function test_the_copy_paste_briefing_and_the_helper_know_what_is_remembered(): void
    {
        $this->memories->add($this->by, null, 'Learns best from a worked example before the theory.', 'tutor');
        $this->memories->add($this->by, $this->algorithms, 'Exam on 14 January.', 'student');
        $session = app(Sessions::class)->start($this->by, $this->algorithms, null, $this->module);

        $briefing = app(Briefings::class)->forSession($this->by, $session->id)->markdown;
        $this->assertStringContainsString("Nothing written yet.\n\n### What their tutors remember about them\n- Learns best from a worked example before the theory. (all courses)\n- Exam on 14 January.", $briefing);

        // The helper: only what holds in every course (its context is the smallest).
        $helper = app(Stack::class)->helper($this->by, $this->module, [])->system;
        $this->assertStringContainsString("## The student\nWhat their tutor remembers about them:\n- Learns best from a worked example before the theory.", $helper);
        $this->assertStringNotContainsString('Exam on 14 January.', $helper);

        // Turned off: neither is told.
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'consent' => true, 'tutor_remembers' => false]);
        $this->assertStringNotContainsString('What their tutors remember', app(Briefings::class)->forSession($this->by, $session->id)->markdown);
        // (Their first name is still there: it is from their account, not something remembered.)
        $this->assertStringNotContainsString('What their tutor remembers', app(Stack::class)->helper($this->by, $this->module, [])->system);
    }
}
