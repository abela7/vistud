<?php

namespace Tests\Feature\Engine;

use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\Settings;
use App\Study\Sessions;
use App\Study\Workspaces;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The shell's way to the engine: the models it offers, and a word in a session's chat. */
class EngineCommandsTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    public function test_the_models_are_listed_and_a_session_is_spoken_to(): void
    {
        Cache::flush();
        $engine = new Fake;
        $this->app->instance(Engine::class, $engine);

        // One substring per line of output: the harness matches each written line against one expectation.
        $this->artisan('vistud:engine:models', ['--find' => 'quick'])->expectsOutputToContain('$0.10 in · $0.40 out per million tokens')->expectsOutputToContain('1 models.')->assertSuccessful();
        $this->artisan('vistud:engine:models', ['--find' => 'fake/'])->expectsOutputToContain('3 models.')->assertSuccessful();
        $this->artisan('vistud:engine:models', ['--find' => 'nothing-like-it'])->expectsOutputToContain('No model matches.')->assertFailed();

        $ada = $this->student(['email' => 'ada@example.test']);
        $by = $this->principal($ada);
        $workspace = app(Workspaces::class)->create($by, ['name' => 'Databases']);
        $session = app(Sessions::class)->start($by, $workspace->id);
        $this->artisan('vistud:engine:ask', ['session' => $session->id, 'text' => 'Hi', '--user' => 'ada@example.test'])->expectsOutputToContain('Add your OpenRouter key')->assertFailed();

        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        $this->artisan('vistud:engine:ask', ['session' => $session->id, 'text' => 'Hi', '--user' => 'ada@example.test'])->expectsOutputToContain('Choose a model')->assertFailed();
        app(Settings::class)->set($by, ['tutor_model' => 'fake/tutor', 'consent' => true]);
        $engine->will(Fake::calls('topics'), Fake::says('Hello, Ada\'s course has no topics yet.', 2_000));
        $this->artisan('vistud:engine:ask', ['session' => $session->id, 'text' => 'Hi', '--user' => 'ada@example.test'])
            ->expectsOutputToContain('Hello, Ada\'s course has no topics yet.')->expectsOutputToContain('Looked up: topics')->expectsOutputToContain('this turn: $0.00')->assertSuccessful();
        $this->artisan('vistud:engine:ask', ['session' => $session->id, 'text' => 'Hi', '--user' => 'nobody@example.test'])->expectsOutputToContain('No account has the email')->assertFailed();
    }
}
