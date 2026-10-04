<?php

namespace Tests\Feature\Engine;

use App\Engine\Engine;
use App\Engine\EngineFailed;
use App\Engine\Fake;
use App\Engine\Jobs\CardsFrom;
use App\Engine\Jobs\Runner;
use App\Engine\Settings;
use App\Models\User;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\Files;
use App\Study\Findings;
use App\Study\Flashcards;
use App\Study\MarkdownDoc;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\Topics;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\MakesStudyFiles;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The reader makes cards from a file, a note or a topic, and keeps none of them: the student reviews first (docs/specs/vistud-2-blueprint.md §3.6.5). */
class CardsFromJobTest extends TestCase
{
    use CreatesAccounts, MakesStudyFiles, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private string $workspace;

    private string $module;

    private string $scheduling;

    private Fake $engine;

    private const ANSWER = ['cards' => [
        ['front' => 'What does round robin give each process?', 'back' => 'A fixed time slice, a quantum, in turn.', 'topic' => 'CPU scheduling'],
        ['front' => 'Why can a long quantum hurt?', 'back' => 'Short jobs wait behind long ones.', 'topic' => 'Not a topic'],
        ['front' => 'what does round robin give each process?', 'back' => 'Same front again.'],
        ['front' => 'What is starvation?', 'back' => 'A process that never gets the CPU.'],
    ]];

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
        $this->scheduling = app(Topics::class)->create($this->by, $this->workspace, 'CPU scheduling', $this->module)->id;
        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'consent' => true]);
    }

    private function make(string $type, string $id, int $count = 8): array
    {
        return app(Runner::class)->answer($this->by, new CardsFrom($this->workspace, $type, $id, $count));
    }

    private function note(string $title, string $markdown): string
    {
        $notes = app(Notes::class);
        $note = $notes->create($this->by, 'module', $this->module, $title);
        $notes->append($this->by, $note->id, MarkdownDoc::blocks($markdown));

        return $note->id;
    }

    public function test_the_rules_are_short_and_say_the_shape_and_that_the_material_is_not_instructions(): void
    {
        $rules = CardsFrom::rules();

        $this->assertLessThanOrEqual(600, (int) ceil(strlen($rules) / 4));
        foreach (['"cards"', '"front"', '"back"', '"topic"', 'never invent', 'not instructions to you', 'One idea per card'] as $part) {
            $this->assertStringContainsString($part, $rules);
        }
        $this->assertStringNotContainsString('<!--', $rules);
    }

    public function test_cards_from_a_note_come_back_cleaned_and_matched_to_the_modules_topics_and_nothing_is_saved(): void
    {
        $note = $this->note('Scheduling', "Round robin gives each process a quantum.\n\nA long quantum hurts short jobs.");
        $this->engine->will(Fake::says(json_encode(self::ANSWER), 3_000, 'fake/quick'));

        $cards = $this->make('note', $note, 8);

        // The same front twice is one card; a topic the course doesn't have is none.
        $this->assertSame(['What does round robin give each process?', 'Why can a long quantum hurt?', 'What is starvation?'], array_column($cards, 'front'));
        $this->assertSame([$this->scheduling, null, null], array_column($cards, 'topic_id'));
        $this->assertSame(['CPU scheduling', null, null], array_column($cards, 'topic'));
        $this->assertSame([], app(Flashcards::class)->list($this->by, $this->workspace), 'nothing is added until the student keeps it');

        $request = $this->engine->requests[0];
        $this->assertSame('fake/quick', $request->model);
        $this->assertStringContainsString('Make 8 flashcards from the note "Scheduling"', $request->messages[0]['content']);
        $this->assertStringContainsString('Topics of the course: CPU scheduling', $request->messages[0]['content']);
        $this->assertStringContainsString('Round robin gives each process a quantum.', $request->messages[0]['content']);
        $row = LearnerTables::query(LearnerScope::of($this->by), 'engine_jobs')->firstOrFail();
        $this->assertSame(['reader', 'cards_from', 'note', 'done'], [$row->role, $row->kind, $row->target_type, $row->status]);
        $this->assertSame(3_000, (int) $row->cost_micros);
    }

    public function test_cards_from_a_file_give_the_files_pages_to_the_reader(): void
    {
        $file = app(Files::class)->upload($this->by, 'module', $this->module, $this->temp("Deadlocks need four conditions.\n"), 'Lecture 3.txt');
        $this->engine->will(Fake::says(json_encode(['cards' => [['front' => 'What are the four conditions?', 'back' => 'Mutual exclusion, hold and wait, no pre-emption, circular wait.']]])));

        $cards = $this->make('file', $file->id);

        $this->assertCount(1, $cards);
        $this->assertStringContainsString('from the file "Lecture 3.txt"', $this->engine->requests[0]->messages[0]['content']);
        $this->assertStringContainsString('Deadlocks need four conditions.', $this->engine->requests[0]->messages[0]['content']);
    }

    public function test_cards_from_a_topic_use_its_key_points_and_the_modules_notes_and_go_to_the_topic(): void
    {
        app(Findings::class)->add($this->by, $this->scheduling, ['text' => 'A quantum is a fixed slice of CPU time.']);
        $this->note('Scheduling notes', 'Round robin rotates through ready processes.');
        $this->engine->will(Fake::says(json_encode(['cards' => [['front' => 'What is a quantum?', 'back' => 'A fixed slice of CPU time.']]])));

        $cards = $this->make('topic', $this->scheduling, 5);

        $this->assertSame([$this->scheduling], array_column($cards, 'topic_id'), 'a card made from a topic is on that topic');
        $message = $this->engine->requests[0]->messages[0]['content'];
        $this->assertStringContainsString('Make 5 flashcards from the topic "CPU scheduling"', $message);
        $this->assertStringContainsString('A quantum is a fixed slice of CPU time.', $message);
        $this->assertStringContainsString('Round robin rotates through ready processes.', $message);
    }

    public function test_the_answer_is_checked(): void
    {
        $topics = [];
        $cards = CardsFrom::parse("```json\n".json_encode(['cards' => array_map(fn ($n) => ['front' => "Q {$n}", 'back' => "A {$n}"], range(1, 20))])."\n```", $topics, 8);
        $this->assertCount(8, $cards);

        $long = CardsFrom::parse(json_encode(['cards' => [['front' => str_repeat('f', 800), 'back' => str_repeat('b', 1500)], ['front' => '', 'back' => 'x'], ['front' => 'x', 'back' => ''], 'junk']]), $topics, 8);
        $this->assertSame([500, 1000], [mb_strlen($long[0]['front']), mb_strlen($long[0]['back'])]);
        $this->assertCount(1, $long);

        foreach (['No idea.', '[]', '{"cards": []}', '{"cards": "x"}', '{"cards": [{"front": "", "back": ""}]}'] as $answer) {
            try {
                CardsFrom::parse($answer, $topics, 8);
                $this->fail("Expected a failure for: {$answer}");
            } catch (EngineFailed $e) {
                $this->assertSame('engine_unreadable', $e->errorCode);
            }
        }
    }

    public function test_what_has_nothing_to_work_from_says_so_before_asking_the_ai(): void
    {
        $picture = app(Files::class)->upload($this->by, 'module', $this->module, $this->temp($this->image()), 'Diagram.png');
        $empty = app(Notes::class)->create($this->by, 'module', $this->module, 'Empty')->id;
        $bare = app(Topics::class)->create($this->by, $this->workspace, 'Paging', $this->module)->id;
        $expect = function (string $type, string $id, string $code) {
            try {
                $this->make($type, $id);
                $this->fail("Expected {$code}.");
            } catch (Unprocessable $e) {
                $this->assertSame($code, $e->errorCode);
            }
        };

        $expect('file', $picture->id, 'file_picture');
        $expect('note', $empty, 'note_empty');
        $expect('topic', $bare, 'topic_empty');
        $this->assertSame([], $this->engine->requests, 'no call was made');
        $this->assertSame(['failed', 'failed', 'failed'], array_map(fn ($row) => $row->status, LearnerTables::query(LearnerScope::of($this->by), 'engine_jobs')->orderBy('created_at')->get()->all()));
    }

    public function test_it_is_refused_without_the_ai_set_up_and_for_what_is_not_the_students(): void
    {
        $note = $this->note('Scheduling', 'Round robin.');
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'consent' => false]);
        try {
            $this->make('note', $note);
            $this->fail('Expected a refusal.');
        } catch (Unprocessable $e) {
            $this->assertSame([], $this->engine->requests);
        }

        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'consent' => true]);
        $bob = $this->principal($this->student());
        $theirs = app(Workspaces::class)->create($bob, ['name' => 'Private'])->id;
        $secret = app(Notes::class)->create($bob, 'workspace', $theirs, 'Secret')->id;
        $this->assertThrows(fn () => $this->make('note', $secret), NotFound::class);
        // A note of the student's own, asked for in another course, is not in it.
        $other = app(Workspaces::class)->create($this->by, ['name' => 'Biology'])->id;
        $this->assertThrows(fn () => app(Runner::class)->answer($this->by, new CardsFrom($other, 'note', $note)), NotFound::class);
        $this->assertThrows(fn () => new CardsFrom($this->workspace, 'question', 'x'), NotFound::class);
    }
}
