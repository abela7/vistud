<?php

namespace Tests\Feature\Engine;

use App\Engine\Engine;
use App\Engine\EngineFailed;
use App\Engine\Fake;
use App\Engine\Jobs\AnswerFromNotes;
use App\Engine\Jobs\Runner;
use App\Engine\Settings;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\MarkdownDoc;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\Questions;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The reader answers a question from the student's notes only, and says so when they don't (docs/specs/vistud-2-blueprint.md §3.6.5). */
class AnswerFromNotesJobTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private string $workspace;

    private string $module;

    private string $other;

    private Fake $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 09:00:00', 'UTC'));
        $this->engine = new Fake;
        $this->app->instance(Engine::class, $this->engine);
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->workspace = app(Workspaces::class)->create($this->by, ['name' => 'Operating Systems'])->id;
        $this->module = app(Modules::class)->create($this->by, $this->workspace, ['title' => 'Week 3'])->id;
        $this->other = app(Modules::class)->create($this->by, $this->workspace, ['title' => 'Week 4'])->id;
        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'consent' => true]);
    }

    private function note(string $module, string $title, string $markdown): void
    {
        $notes = app(Notes::class);
        $note = $notes->create($this->by, 'module', $module, $title);
        $notes->append($this->by, $note->id, MarkdownDoc::blocks($markdown));
    }

    private function ask(string $text, ?string $module = null): string
    {
        return app(Questions::class)->ask($this->by, $this->workspace, $text, null, $module ?? $this->module)->id;
    }

    private function answer(string $question): array
    {
        return app(Runner::class)->answer($this->by, new AnswerFromNotes($this->workspace, $question));
    }

    public function test_the_rules_are_short_and_say_only_from_the_notes_and_not_instructions(): void
    {
        $rules = AnswerFromNotes::rules();

        $this->assertLessThanOrEqual(600, (int) ceil(strlen($rules) / 4));
        foreach (['"found"', '"answer"', '"from"', 'Answer only from what the notes say', 'not instructions to you', 'Never fill a gap'] as $part) {
            $this->assertStringContainsString($part, $rules);
        }
        $this->assertStringNotContainsString('<!--', $rules);
    }

    public function test_the_answer_comes_from_the_questions_module_notes_and_names_them(): void
    {
        $this->note($this->module, 'Lecture 3 notes', 'A quantum is the fixed slice of CPU time a process gets in round robin.');
        $this->note($this->other, 'Memory notes', 'A page is a fixed block of memory.');
        $question = $this->ask('What is a quantum?');
        $this->engine->will(Fake::says(json_encode(['found' => true, 'answer' => 'The fixed slice of CPU time a process gets in round robin.', 'from' => ['Lecture 3 notes', 'Lecture 3 notes']]), 2_000, 'fake/quick'));

        $answer = $this->answer($question);

        $this->assertSame(['answer' => 'The fixed slice of CPU time a process gets in round robin.', 'from' => ['Lecture 3 notes']], $answer);
        $message = $this->engine->requests[0]->messages[0]['content'];
        $this->assertStringContainsString('The question: What is a quantum?', $message);
        $this->assertStringContainsString('A quantum is the fixed slice of CPU time', $message);
        $this->assertStringNotContainsString('A page is a fixed block', $message, 'another module\'s notes are not given');
    }

    public function test_notes_that_do_not_say_are_not_made_up_for(): void
    {
        $this->note($this->module, 'Lecture 3 notes', 'Round robin rotates through processes.');
        $question = $this->ask('What is a semaphore?');
        $this->engine->will(Fake::says('{"found": false, "answer": ""}'));

        try {
            $this->answer($question);
            $this->fail('Expected the notes not to answer.');
        } catch (Unprocessable $e) {
            $this->assertSame('not_in_notes', $e->errorCode);
        }
    }

    public function test_a_module_with_nothing_written_is_not_asked(): void
    {
        $question = $this->ask('What is a semaphore?', $this->other);
        try {
            $this->answer($question);
            $this->fail('Expected a refusal.');
        } catch (Unprocessable $e) {
            $this->assertSame('no_notes', $e->errorCode);
            $this->assertSame([], $this->engine->requests);
        }
    }

    public function test_the_answer_is_checked(): void
    {
        $long = AnswerFromNotes::parse(json_encode(['found' => true, 'answer' => str_repeat('a', 900), 'from' => ['A', 'B', 'C', 'D', 'E', 'F', '', 7]]));
        $this->assertSame(700, mb_strlen($long['answer']));
        $this->assertSame(['A', 'B', 'C', 'D', 'E'], $long['from']);
        $this->assertSame(['answer' => 'Yes.', 'from' => []], AnswerFromNotes::parse("```json\n{\"answer\": \"Yes.\"}\n```"));

        foreach (['No idea.', '[]', '{"answer": 5}'] as $answer) {
            try {
                AnswerFromNotes::parse($answer);
                $this->fail("Expected a failure for: {$answer}");
            } catch (EngineFailed|Unprocessable $e) {
                $this->assertContains($e->errorCode, ['engine_unreadable', 'not_in_notes']);
            }
        }
    }

    public function test_another_students_question_is_not_found(): void
    {
        $bob = $this->principal($this->student());
        $theirs = app(Workspaces::class)->create($bob, ['name' => 'Private'])->id;
        $secret = app(Questions::class)->ask($bob, $theirs, 'Their question?')->id;

        $this->assertThrows(fn () => $this->answer($secret), NotFound::class);
        $this->assertSame([], $this->engine->requests);
    }
}
