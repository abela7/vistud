<?php

namespace Tests\Feature\Engine;

use App\Engine\Engine;
use App\Engine\EngineFailed;
use App\Engine\Fake;
use App\Engine\Request;
use App\Engine\SessionChat;
use App\Engine\Settings;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\Briefings;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\SessionDetails;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The built-in chat of a study session, on a scripted engine (docs/specs/study-memory.md §6). */
class SessionChatTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private WorkspaceDetails $databases;

    private SessionDetails $session;

    private Fake $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
        $this->engine = new Fake;
        $this->app->instance(Engine::class, $this->engine);
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->databases = app(Workspaces::class)->create($this->by, ['name' => 'Databases']);
        $week2 = app(Modules::class)->create($this->by, $this->databases->id, ['title' => 'Week 2: SQL joins'])->id;
        $joins = app(Topics::class)->create($this->by, $this->databases->id, 'Joins', $week2)->id;
        $note = app(Notes::class)->create($this->by, 'module', $week2, 'Lecture 3: joins');
        app(Notes::class)->save($this->by, $note->id, ['base_version' => 1, 'save_id' => 'engine-save-1', 'client_id' => 'engine-tab-1', 'title' => 'Lecture 3: joins', 'doc' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'A left join keeps every left row.']]]]]]);
        $this->session = app(Sessions::class)->start($this->by, $this->databases->id, $joins, $week2);
        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'quick_model' => 'fake/quick', 'fallback_model' => 'fake/plain', 'consent' => true]);
    }

    private function chat(): SessionChat
    {
        return app(SessionChat::class);
    }

    public function test_a_turn_sends_the_briefing_and_the_tools_runs_the_look_ups_and_keeps_every_message_with_its_cost(): void
    {
        $this->engine->will(
            Fake::calls('read_note', ['note' => 'Lecture 3'], 'call_1'),
            function (Request $request) {
                // The look-up's result went back to the engine, after its own call.
                $this->assertSame('tool', $request->messages[2]['role']);
                $this->assertSame('call_1', $request->messages[2]['tool_call_id']);
                $this->assertStringContainsString('A left join keeps every left row.', $request->messages[2]['content']);
                $this->assertSame('call_1', $request->messages[1]['tool_calls'][0]['id']);

                return Fake::says("Your note says a left join keeps every left row.\n\n<finding topic=\"Joins\">A left join keeps every row of the left table.</finding>", 2_500);
            },
        );

        $reply = $this->chat()->send($this->by, $this->session->id, 'What did my lecture say about left joins?');
        $this->assertStringStartsWith('Your note says', $reply->text);

        $first = $this->engine->requests[0];
        $this->assertSame('fake/tutor', $first->model);
        $this->assertSame(['fake/plain'], $first->fallbacks);
        $this->assertTrue($first->noTraining);
        $this->assertStringStartsWith('# You are the student\'s tutor', $first->system);
        $this->assertStringContainsString('## Looking things up', $first->system);
        $this->assertStringContainsString('Joins', $first->system);
        $this->assertSame([['role' => 'user', 'content' => 'What did my lecture say about left joins?']], $first->messages);
        $this->assertContains('read_note', array_map(fn ($t) => $t['function']['name'], $first->tools));

        $turns = $this->chat()->transcript($this->by, $this->session->id);
        $this->assertSame(['user', 'assistant'], array_column($turns, 'role'));
        $this->assertSame(['read_note'], $turns[1]['tools']);
        $this->assertSame(3_500, $turns[1]['cost_micros']);
        $spent = $this->chat()->spent($this->by, $this->session->id);
        $this->assertSame([3_500, 3_500, Settings::DEFAULT_SESSION_CAP, Settings::DEFAULT_MONTH_CAP], [$spent['session'], $spent['month'], $spent['session_cap'], $spent['month_cap']]);

        // What the tutor marked is ready for the write-back, exactly as a pasted chat would be.
        $proposals = $this->chat()->proposals($this->by, $this->session->id);
        $this->assertSame(['finding', 'A left join keeps every row of the left table.', 'Joins'], [$proposals[0]['kind'], $proposals[0]['text'], $proposals[0]['topic_name']]);

        // The next turn carries the whole chat so far, tools included.
        $this->engine->will(Fake::says('Yes.'));
        $this->chat()->send($this->by, $this->session->id, 'Is that always true?');
        $this->assertSame(['user', 'assistant', 'tool', 'assistant', 'user'], array_column($this->engine->last()->messages, 'role'));
    }

    public function test_a_model_without_tools_gets_none_and_the_look_up_rounds_are_limited(): void
    {
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/plain', 'consent' => true]);
        $this->engine->will(Fake::says('Hello.'));
        $this->chat()->send($this->by, $this->session->id, 'Hi');
        $this->assertSame([], $this->engine->last()->tools);
        $this->assertStringNotContainsString('## Looking things up', $this->engine->last()->system);

        config(['vistud.engine.tool_rounds' => 2]);
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'consent' => true]);
        $this->engine->will(Fake::calls('topics', [], 'a'), Fake::calls('topics', [], 'b'), Fake::says('Enough.'));
        $reply = $this->chat()->send($this->by, $this->session->id, 'Loop');
        // Two rounds with tools, then the last call offers none, so the engine must answer.
        $this->assertSame([true, true, false], array_map(fn ($r) => $r->tools !== [], array_slice($this->engine->requests, -3)));
        $this->assertSame('Enough.', $reply->text);
    }

    public function test_the_chat_refuses_without_a_model_or_consent_over_the_limits_and_once_the_session_has_ended(): void
    {
        $this->engine->will(Fake::says('Hi.', 1_500_000));
        $this->chat()->send($this->by, $this->session->id, 'Hi');
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'session_cap' => '1.00', 'consent' => true]);
        $this->expectCode(fn () => $this->chat()->send($this->by, $this->session->id, 'More'), 'engine_cap', '$1.00');
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'session_cap' => '5.00', 'month_cap' => '1.00', 'consent' => true]);
        $this->expectCode(fn () => $this->chat()->send($this->by, $this->session->id, 'More'), 'engine_cap', 'month');
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'session_cap' => '0', 'month_cap' => '0', 'consent' => true]);
        $this->engine->will(Fake::says('No limits.'));
        $this->assertSame('No limits.', $this->chat()->send($this->by, $this->session->id, 'More')->text);

        app(Settings::class)->set($this->by, ['tutor_model' => '', 'consent' => true]);
        $this->expectCode(fn () => $this->chat()->send($this->by, $this->session->id, 'Hi'), 'engine_model');
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'consent' => false]);
        $this->expectCode(fn () => $this->chat()->send($this->by, $this->session->id, 'Hi'), 'engine_consent');
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'consent' => true]);
        $this->expectCode(fn () => $this->chat()->send($this->by, $this->session->id, '   '), 'validation_failed');

        app(Sessions::class)->end($this->by, $this->session->id);
        $this->expectCode(fn () => $this->chat()->send($this->by, $this->session->id, 'Hi'), 'session_ended');
        // Reading stays possible: the two turns that went through.
        $this->assertCount(4, $this->chat()->transcript($this->by, $this->session->id));
    }

    public function test_a_long_chat_folds_its_oldest_turns_into_a_summary_the_engine_reads_instead(): void
    {
        config(['vistud.engine.fold_at' => 900, 'vistud.engine.keep_recent' => 2]);
        $long = str_repeat('Joins join tables. ', 20);
        $this->engine->will(Fake::says($long), Fake::says($long));
        $this->chat()->send($this->by, $this->session->id, 'One');
        $this->chat()->send($this->by, $this->session->id, 'Two');
        $this->assertStringNotContainsString('## Earlier in this chat', $this->engine->last()->system);
        // Over the size with the third turn: its answer is followed by the fold, done by the quick model.
        $this->engine->will(Fake::says($long), function (Request $request) {
            $this->assertSame('fake/quick', $request->model);
            $this->assertStringContainsString('Student: One', $request->messages[0]['content']);
            $this->assertStringContainsString('Student: Two', $request->messages[0]['content']);
            $this->assertStringNotContainsString('Student: Three', $request->messages[0]['content']);

            return Fake::says('The student asked about joins twice; the tutor explained that joins join tables.', 200, 'fake/quick');
        });
        $this->chat()->send($this->by, $this->session->id, 'Three');

        $this->engine->will(Fake::says('Four.'));
        $this->chat()->send($this->by, $this->session->id, 'Four');
        $last = $this->engine->last();
        $this->assertStringContainsString('## Earlier in this chat', $last->system);
        $this->assertStringContainsString('joins join tables', $last->system);
        // Only the turns after the fold are sent whole.
        $this->assertSame(['Three', $long, 'Four'], array_map(fn ($m) => $m['content'], $last->messages));
        $turns = $this->chat()->transcript($this->by, $this->session->id);
        $this->assertSame([true, true, true, true, false, false, false, false], array_column($turns, 'folded'));
        // The fold's own cost counts too.
        $this->assertSame(4 * 1_000 + 200, $this->chat()->spent($this->by, $this->session->id)['session']);
    }

    public function test_the_students_own_key_goes_with_every_request_and_without_any_key_the_chat_says_so(): void
    {
        $this->engine->will(Fake::says('Hi.'));
        $this->chat()->send($this->by, $this->session->id, 'Hi');
        $this->assertNull($this->engine->last()->key);

        app(Settings::class)->setKey($this->by, 'sk-or-v1-abcdefghijklmnopqrstuvwxyz');
        $this->engine->will(Fake::says('Hi again.'));
        $this->chat()->send($this->by, $this->session->id, 'Hi');
        $this->assertSame('sk-or-v1-abcdefghijklmnopqrstuvwxyz', $this->engine->last()->key);

        app(Settings::class)->removeKey($this->by);
        config(['vistud.engine.key' => '']);
        $this->expectCode(fn () => $this->chat()->send($this->by, $this->session->id, 'Hi'), 'engine_key', 'your AI engine settings');
    }

    public function test_ending_wraps_the_chat_into_the_sessions_summary_and_checkpoint_once_for_the_next_briefing(): void
    {
        $this->engine->will(Fake::says('A left join keeps every left row.'), Fake::says('Right. **Slide 4 · Right joins** mirror it.'));
        $this->chat()->send($this->by, $this->session->id, 'What is a left join?');
        $this->chat()->send($this->by, $this->session->id, 'And a right join?');
        app(Sessions::class)->end($this->by, $this->session->id);

        $this->engine->will(Fake::says("```json\n{\"summary\": \"Left joins were explained and understood; right joins were started.\", \"checkpoint\": \"Slide 4 of 12; right joins next.\"}\n```", 300, 'fake/quick'));
        $written = $this->chat()->wrapUp($this->by, $this->session->id);
        $this->assertSame(['summary' => 'Left joins were explained and understood; right joins were started.', 'checkpoint' => 'Slide 4 of 12; right joins next.'], $written);
        $request = $this->engine->last();
        $this->assertSame('fake/quick', $request->model);
        $this->assertStringContainsString('one JSON object', $request->system);
        $this->assertStringContainsString('Student: What is a left join?', $request->messages[0]['content']);
        $this->assertStringContainsString('Tutor: Right.', $request->messages[0]['content']);
        $session = app(Sessions::class)->find($this->by, $this->session->id);
        $this->assertSame([$written['summary'], $written['checkpoint']], [$session->summary, $session->checkpoint]);
        $this->assertSame(2_300, $this->chat()->spent($this->by, $this->session->id)['session']);

        // Once: a second call asks the engine nothing.
        $asked = count($this->engine->requests);
        $this->assertNull($this->chat()->wrapUp($this->by, $this->session->id));
        $this->assertCount($asked, $this->engine->requests);

        // The next session's briefing starts from it.
        $next = app(Sessions::class)->start($this->by, $this->databases->id);
        $this->assertStringContainsString('The tutor\'s summary: Left joins were explained', app(Briefings::class)->forSession($this->by, $next->id)->markdown);
    }

    public function test_the_wrap_up_needs_a_chat_keeps_what_the_student_ticked_and_leaves_a_failed_try_for_later(): void
    {
        // No chat yet: nothing to write from, nothing asked.
        $this->assertNull($this->chat()->wrapUp($this->by, $this->session->id));
        $this->assertSame([], $this->engine->requests);

        $this->engine->will(Fake::says('A left join keeps every left row.'));
        $this->chat()->send($this->by, $this->session->id, 'What is a left join?');
        app(Sessions::class)->setSummary($this->by, $this->session->id, 'My own summary.');
        app(Sessions::class)->end($this->by, $this->session->id);

        // The engine fails: nothing is written, and the next try asks again.
        $this->engine->will(fn () => throw new EngineFailed('engine_busy', 'The service is busy.'));
        $this->assertNull($this->chat()->wrapUp($this->by, $this->session->id));
        $this->assertNull(app(Sessions::class)->find($this->by, $this->session->id)->checkpoint);

        // Only the blank is filled: the student\'s own summary stays.
        $this->engine->will(Fake::says('{"summary": "The tutor\'s version.", "checkpoint": "Stopped at slide 2."}', 200, 'fake/quick'));
        $this->assertSame(['summary' => null, 'checkpoint' => 'Stopped at slide 2.'], $this->chat()->wrapUp($this->by, $this->session->id));
        $session = app(Sessions::class)->find($this->by, $this->session->id);
        $this->assertSame(['My own summary.', 'Stopped at slide 2.'], [$session->summary, $session->checkpoint]);
    }

    public function test_another_student_cannot_read_or_write_the_chat(): void
    {
        $this->engine->will(Fake::says('Hi.'));
        $this->chat()->send($this->by, $this->session->id, 'Hi');
        $other = $this->principal($this->student());
        $this->expectException(NotFound::class);
        $this->chat()->transcript($other, $this->session->id);
    }

    private function expectCode(callable $do, string $code, ?string $words = null): void
    {
        try {
            $do();
            $this->fail("Expected {$code}.");
        } catch (Unprocessable|EngineFailed $e) {
            $this->assertSame($code, $e->errorCode);
            if ($words !== null) {
                $this->assertStringContainsString($words, $e->getMessage());
            }
        }
    }

    public function test_a_streamed_turn_tells_what_it_looks_up_and_hands_over_the_words_as_they_come(): void
    {
        $this->engine->will(Fake::calls('read_note', ['note' => 'Lecture 3']), Fake::says('Your note says a left join keeps every left row.'));
        $events = [];
        $reply = $this->chat()->send($this->by, $this->session->id, 'What did my lecture say?', function (string $kind, string $value) use (&$events) {
            $events[] = [$kind, $value];
        });

        $this->assertSame(['looking', 'read_note'], $events[0]);
        $words = array_column(array_filter($events, fn ($e) => $e[0] === 'text'), 1);
        $this->assertGreaterThan(1, count($words));
        $this->assertSame($reply->text, implode('', $words));
        $this->assertSame(['user', 'assistant'], array_column($this->chat()->transcript($this->by, $this->session->id), 'role'));
    }

    public function test_a_turn_the_engine_failed_on_is_tried_again_without_sending_the_words_twice(): void
    {
        $this->engine->will(Fake::calls('topics', [], 'call_1'), fn () => throw new EngineFailed('engine_busy', 'The engine is busy right now.'));
        $this->expectCode(fn () => $this->chat()->send($this->by, $this->session->id, 'What should I study?'), 'engine_busy');
        $this->assertTrue($this->chat()->waiting($this->by, $this->session->id));
        // The look-up round that went through still counts.
        $this->assertSame(1_000, $this->chat()->spent($this->by, $this->session->id)['session']);

        $this->engine->will(Fake::says('Joins: they still confuse you.'));
        $this->assertSame('Joins: they still confuse you.', $this->chat()->retry($this->by, $this->session->id)->text);
        // It went on from the look-up: the words once, the call and its result, no second question.
        $this->assertSame(['user', 'assistant', 'tool'], array_column($this->engine->last()->messages, 'role'));
        $this->assertFalse($this->chat()->waiting($this->by, $this->session->id));
        $this->assertSame(['user', 'assistant'], array_column($this->chat()->transcript($this->by, $this->session->id), 'role'));
        $this->expectCode(fn () => $this->chat()->retry($this->by, $this->session->id), 'nothing_to_retry');
    }
}
