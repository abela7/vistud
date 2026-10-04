<?php

namespace Tests\Feature\Engine;

use App\Console\Commands\PromptsTry;
use App\Engine\Context\Stack;
use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\Toolbox;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** php artisan prompts:try: the tutor's prompt tried on a model, with canned moments of a session. */
class PromptsTryTest extends TestCase
{
    use RefreshesDatabase;

    private Fake $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new Fake;
        $this->app->instance(Engine::class, $this->engine);
        config(['vistud.engine.key' => 'sk-or-owner-000000000000000', 'vistud.engine.tutor_model' => '']);
    }

    public function test_it_sends_five_moments_with_the_real_rules_and_tools_and_prints_what_comes_back(): void
    {
        $this->engine->will(
            Fake::calls('add_topics', ['topics' => [['name' => 'FCFS']]]),
            Fake::says('**Slide 4 of 18 · Shortest job first**'),
            Fake::calls('make_flashcards', ['cards' => [['front' => 'What is a quantum?', 'back' => 'The time slice.']]]),
            Fake::says('**Question 1 of 5**'),
            Fake::says('I can\'t write that for you, but let\'s work through it.'),
        );

        $this->artisan('prompts:try', ['--model' => 'fake/tutor'])
            ->expectsOutputToContain('1. A lecture is shared in a session with no topic')
            ->expectsOutputToContain('→ asks for add_topics {"topics":[{"name":"FCFS"}]}')
            ->expectsOutputToContain('5. A request to write graded work')
            ->expectsOutputToContain('I can\'t write that for you')
            ->expectsOutputToContain('All together:')
            ->assertSuccessful();

        $this->assertCount(5, $this->engine->requests);
        foreach ($this->engine->requests as $request) {
            $this->assertSame('fake/tutor', $request->model);
            $this->assertStringStartsWith("# You are the student's tutor", $request->system);
            $this->assertStringContainsString('## The course: Operating Systems', $request->system);
            $this->assertSame(count(app(Toolbox::class)->definitions()), count($request->tools));
            $this->assertSame('user', $request->messages[array_key_last($request->messages)]['role']);
        }
        // The moments carry their conversation: "next" comes after the tutor's own words.
        $this->assertSame(['user', 'assistant', 'user'], array_column($this->engine->requests[1]->messages, 'role'));
        $this->assertSame('next', $this->engine->requests[1]->messages[2]['content']);
        // The first moment has no topic, the others one.
        $this->assertStringContainsString('Topic now: none chosen.', $this->engine->requests[0]->system);
        $this->assertStringContainsString('Topic now: Round robin', $this->engine->requests[2]->system);
    }

    public function test_one_moment_can_be_tried_alone_and_a_model_without_tools_without_them(): void
    {
        $this->engine->will(Fake::says('Here are three cards.'));
        $this->artisan('prompts:try', ['--model' => 'fake/plain', '--scenario' => '3', '--plain' => true])->assertSuccessful();

        $this->assertCount(1, $this->engine->requests);
        $request = $this->engine->requests[0];
        $this->assertSame([], $request->tools);
        $this->assertStringNotContainsString('## Your tools in this chat', $request->system);
        $this->assertStringContainsString('<flashcard topic="Topic name">', $request->system);
        $this->assertSame('make 3 flashcards on round robin', $request->messages[0]['content']);
    }

    public function test_it_needs_a_key_and_a_model_and_says_so(): void
    {
        config(['vistud.engine.key' => '']);
        $this->artisan('prompts:try', ['--model' => 'fake/tutor'])->expectsOutputToContain('No key is set for everyone.')->assertFailed();

        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        $this->artisan('prompts:try')->expectsOutputToContain('Name a model with --model=')->assertFailed();
        $this->assertSame([], $this->engine->requests);
    }

    public function test_the_canned_moments_fit_the_standing_contexts_budgets(): void
    {
        $stack = app(Stack::class);
        $this->assertCount(5, PromptsTry::scenarios());
        foreach (PromptsTry::scenarios() as $number => [$title, $facts]) {
            $built = $stack->compose($facts, true, app(Toolbox::class)->definitions());
            $this->assertSame([], $built->cuts(), "Moment {$number} ({$title}) loses nothing.");
        }
    }
}
