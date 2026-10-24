<?php

namespace Tests\Feature\Engine;

use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\SessionChat;
use App\Engine\Settings;
use App\Identity\PrincipalFactory;
use App\Jobs\WrapUpSession;
use App\Livewire\Study\WrapUpWatch;
use App\Livewire\Workspaces\StudySession;
use App\Study\Modules;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\Workspaces;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * Ending a session never waits for its summary: it is written in the background (App\Jobs\WrapUpSession), the end
 * screen names the model writing it, and the student's pages say when it is saved (the owner's ask, 2026-10-24).
 */
class WrapUpInBackgroundTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    public function test_ending_queues_the_summary_the_end_screen_says_it_is_being_written_and_the_pages_say_when_it_is_saved(): void
    {
        $this->withoutVite();
        $this->app->instance(Engine::class, $engine = new Fake);
        $user = $this->student();
        $this->actingAs($user);
        $by = $this->principal($user);
        $this->ownAiKey($by);
        app(Settings::class)->set($by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'consent' => true]);
        $course = app(Workspaces::class)->create($by, ['name' => 'Databases']);
        $week = app(Modules::class)->create($by, $course->id, ['title' => 'Week 2'])->id;
        $joins = app(Topics::class)->create($by, $course->id, 'Joins', $week)->id;
        $session = app(Sessions::class)->start($by, $course->id, $joins, $week);
        $engine->will(Fake::says('A join combines rows from two tables.'));
        app(SessionChat::class)->send($by, $session->id, 'What is a join?');
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        // Ending: the session ends at once, and the summary waits on the queue.
        Queue::fake();
        Livewire::test(StudySession::class, ['workspaceId' => $course->id, 'sessionId' => $session->id])
            ->call('confirmEnd')->call('save')
            ->assertSet('mode', 'done')->assertSee('is writing your summary in the background')->assertDispatched('wrap-up-started');
        Queue::assertPushed(WrapUpSession::class, fn (WrapUpSession $job) => $job->sessionId === $session->id);
        $this->assertFalse(app(Sessions::class)->find($by, $session->id)->isOpen());
        $this->assertSame('writing', app(SessionChat::class)->wrapState($by, $session->id));
        $this->assertSame([$session->id], session(WrapUpWatch::KEY));
        Livewire::test(WrapUpWatch::class)->assertSet('notice', null)->assertSeeHtml('wire:poll.8s="check"');

        // The worker writes it with the reader; the next check says it is saved, once.
        $engine->will(Fake::says('{"summary": "Studied joins: a join combines rows.", "checkpoint": "Joins; next, outer joins."}', 100, 'fake/quick'));
        (new WrapUpSession((string) $user->id, $session->id))->handle(app(SessionChat::class), app(PrincipalFactory::class));
        $this->assertSame('fake/quick', $engine->last()->model);
        $this->assertSame('done', app(SessionChat::class)->wrapState($by, $session->id));
        Livewire::test(WrapUpWatch::class)->assertSet('notice', 'Your session summary is saved.');
        $this->assertSame([], session(WrapUpWatch::KEY));
        Livewire::test(WrapUpWatch::class)->assertSet('notice', null)->assertDontSeeHtml('wire:poll');
        $this->assertSame('Studied joins: a join combines rows.', app(Sessions::class)->find($by, $session->id)->summary);
    }

    public function test_a_session_with_nothing_to_summarise_queues_nothing(): void
    {
        $this->withoutVite();
        $this->app->instance(Engine::class, new Fake);
        $user = $this->student();
        $this->actingAs($user);
        $by = $this->principal($user);
        $course = app(Workspaces::class)->create($by, ['name' => 'Databases']);
        $session = app(Sessions::class)->start($by, $course->id, null, null);
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        Queue::fake();
        Livewire::test(StudySession::class, ['workspaceId' => $course->id, 'sessionId' => $session->id])
            ->call('confirmEnd')->call('save')->assertSet('mode', 'done')->assertDontSee('writing your summary');
        Queue::assertNothingPushed();
    }
}
