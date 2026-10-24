<?php

namespace Tests\Feature\Engine;

use App\Engine\Engine;
use App\Engine\EngineFailed;
use App\Engine\Fake;
use App\Engine\Request;
use App\Engine\SessionChat;
use App\Engine\Settings;
use App\Livewire\Workspaces\TutorChat;
use App\Study\Modules;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\Workspaces;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** A study session's "Compact now", and the summary the AI reads, shown in the chat's gear (the owner's ask, 2026-10-24). */
class CompactNowTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    public function test_compact_now_folds_a_short_chat_shows_the_summary_and_the_ai_reads_it_after(): void
    {
        $this->withoutVite();
        $this->app->instance(Engine::class, $engine = new Fake);
        config(['vistud.engine.keep_recent' => 2]);
        $user = $this->student();
        $this->actingAs($user);
        $by = $this->principal($user);
        $this->ownAiKey($by);
        app(Settings::class)->set($by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'consent' => true]);
        $course = app(Workspaces::class)->create($by, ['name' => 'Databases']);
        $week = app(Modules::class)->create($by, $course->id, ['title' => 'Week 2'])->id;
        $joins = app(Topics::class)->create($by, $course->id, 'Joins', $week)->id;
        $session = app(Sessions::class)->start($by, $course->id, $joins, $week);
        $chat = app(SessionChat::class);
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        // One turn: nothing older than the last messages, so nothing to fold.
        $engine->will(Fake::says('A join combines rows.'));
        $chat->send($by, $session->id, 'What is a join?');
        $this->assertSame('short', $chat->compact($by, $session->id));
        Livewire::test(TutorChat::class, ['workspaceId' => $course->id, 'sessionId' => $session->id])
            ->assertSee('Chat memory')->assertSee('Nothing is summarised yet')
            ->call('compactNow')->assertSee('Nothing to compact yet');

        // Two turns, far under the automatic size: Compact now folds the first anyway.
        $engine->will(Fake::says('A left join keeps every left row.'), function (Request $request) {
            $this->assertSame('fake/quick', $request->model);
            $this->assertStringContainsString('Student: What is a join?', $request->messages[0]['content']);

            return Fake::says('The student asked what a join is; the tutor said it combines rows.', 100, 'fake/quick');
        });
        $chat->send($by, $session->id, 'And a left join?');
        Livewire::test(TutorChat::class, ['workspaceId' => $course->id, 'sessionId' => $session->id])
            ->call('compactNow')->assertSee('Compacted.')
            ->assertSee('The student asked what a join is; the tutor said it combines rows.');
        $this->assertSame([true, true, false, false], array_column($chat->transcript($by, $session->id), 'folded'));

        // The next turn reads the summary, and only the messages after it whole.
        $engine->will(Fake::says('Right.'));
        $chat->send($by, $session->id, 'Thanks');
        $this->assertStringContainsString('it combines rows', $engine->last()->system);
        $this->assertSame(['And a left join?', 'A left join keeps every left row.', 'Thanks'], array_map(fn ($m) => $m['content'], $engine->last()->messages));
    }

    public function test_a_failed_summary_leaves_the_chat_as_it_was(): void
    {
        $this->app->instance(Engine::class, $engine = new Fake);
        config(['vistud.engine.keep_recent' => 2]);
        $user = $this->student();
        $by = $this->principal($user);
        $this->ownAiKey($by);
        app(Settings::class)->set($by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'consent' => true]);
        $course = app(Workspaces::class)->create($by, ['name' => 'Databases']);
        $session = app(Sessions::class)->start($by, $course->id, null, null);
        $chat = app(SessionChat::class);

        $engine->will(Fake::says('One.'), Fake::says('Two.'), fn () => throw new EngineFailed('engine_unavailable', 'down'));
        $chat->send($by, $session->id, 'First');
        $chat->send($by, $session->id, 'Second');
        $this->assertSame('failed', $chat->compact($by, $session->id));
        $this->assertNull($chat->foldedSummary($by, $session->id));
        $this->assertSame([false, false, false, false], array_column($chat->transcript($by, $session->id), 'folded'));
    }
}
