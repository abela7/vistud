<?php

namespace Tests\Feature\Engine;

use App\Engine\Assist;
use App\Engine\Engine;
use App\Engine\EngineFailed;
use App\Engine\Fake;
use App\Engine\Settings;
use App\Models\User;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\NotFound;
use App\Study\Files;
use App\Study\Flashcards;
use App\Study\Folders;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\Questions;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\MakesStudyFiles;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The quick jobs behind the ✦ menu: each is one task to the helper, read back as a proposal that changes nothing (docs/specs/vistud-2-blueprint.md §3.6.5). */
class AssistTest extends TestCase
{
    use CreatesAccounts, MakesStudyFiles, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private string $workspace;

    private string $module;

    private Fake $engine;

    private Assist $assist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 09:00:00', 'UTC'));
        $this->engine = new Fake;
        $this->app->instance(Engine::class, $this->engine);
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->workspace = app(Workspaces::class)->create($this->by, ['name' => 'Databases'])->id;
        $this->module = app(Modules::class)->create($this->by, $this->workspace, ['title' => 'Week 2: SQL joins'])->id;
        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/plain', 'helper_model' => 'fake/quick', 'consent' => true]);
        $this->assist = app(Assist::class);
    }

    private function card(string $front = 'Can you tell me what it is that a LEFT JOIN keeps?', string $back = 'It keeps every row of the left table.'): string
    {
        return app(Flashcards::class)->add($this->by, $this->workspace, null, $front, $back, moduleId: $this->module);
    }

    private function says(string $text): void
    {
        $this->engine->will(Fake::says($text, 1_000, 'fake/quick'));
    }

    public function test_a_card_is_improved_shortened_or_fixed_as_one_proposed_card_and_the_card_is_not_touched(): void
    {
        $id = $this->card();
        foreach (['improve', 'shorter', 'fix'] as $action) {
            $this->says("Front: What does a LEFT JOIN keep?\nBack: Every row of the left table, matched or not.");
            $proposal = $this->assist->card($this->by, $id, $action);

            $this->assertSame(['front' => 'Can you tell me what it is that a LEFT JOIN keeps?', 'back' => 'It keeps every row of the left table.'], $proposal['before']);
            $this->assertSame([['front' => 'What does a LEFT JOIN keep?', 'back' => 'Every row of the left table, matched or not.']], $proposal['cards']);
            $this->assertStringContainsString(Assist::CARD[$action], $this->engine->last()->messages[0]['content']);
        }
        $this->assertSame('Can you tell me what it is that a LEFT JOIN keeps?', app(Flashcards::class)->find($this->by, $id)->front);
        // Each ran as the helper, in the card's module, and was recorded under the helper.
        $this->assertSame('fake/quick', $this->engine->last()->model);
        $this->assertStringContainsString('Week 2: SQL joins', $this->engine->last()->system);
        $jobs = LearnerTables::query(LearnerScope::of($this->by), 'engine_jobs')->get();
        $this->assertSame(['helper'], $jobs->pluck('role')->unique()->all());
        $this->assertCount(3, $jobs);
    }

    public function test_two_more_cards_like_this_one_come_back_as_two_new_ones(): void
    {
        $id = $this->card();
        $this->says("Front: What does an INNER JOIN keep?\nBack: Only the rows that match on both sides.\n\nFront: What does a RIGHT JOIN keep?\nBack: Every row of the right table.\n\nFront: A third, too many\nBack: Dropped.");

        $proposal = $this->assist->card($this->by, $id, 'more');

        $this->assertSame(['What does an INNER JOIN keep?', 'What does a RIGHT JOIN keep?'], array_column($proposal['cards'], 'front'));
        $this->assertSame(1, count(app(Flashcards::class)->list($this->by, $this->workspace)), 'nothing was added');
    }

    public function test_an_answer_with_no_card_in_it_is_a_failure_and_a_made_up_action_is_not_found(): void
    {
        $id = $this->card();
        $this->says('Sure! Here is a better card.');
        try {
            $this->assist->card($this->by, $id, 'improve');
            $this->fail('Expected a failure.');
        } catch (EngineFailed $e) {
            $this->assertSame('engine_unreadable', $e->errorCode);
        }
        $this->assertThrows(fn () => $this->assist->card($this->by, $id, 'delete'), NotFound::class);
        $this->assertSame([['front' => 'Q', 'back' => 'A']], Assist::cards("**Front:** Q\n**Back:** A", 2));
    }

    public function test_a_question_is_clarified_into_one_or_split_into_two(): void
    {
        $questions = app(Questions::class);
        $id = $questions->ask($this->by, $this->workspace, 'why left join null and also what is a right join', null, $this->module)->id;

        $this->says("Why are the unmatched columns NULL in a LEFT JOIN?\n");
        $one = $this->assist->question($this->by, $id, 'clarify');
        $this->assertSame(['why left join null and also what is a right join', ['Why are the unmatched columns NULL in a LEFT JOIN?']], [$one['before'], $one['questions']]);

        $this->says("1. Why are unmatched columns NULL in a LEFT JOIN?\n2) What does a RIGHT JOIN do?\n3. A third.");
        $two = $this->assist->question($this->by, $id, 'split');
        $this->assertSame(['Why are unmatched columns NULL in a LEFT JOIN?', 'What does a RIGHT JOIN do?'], $two['questions']);

        $this->says('Only one question here.');
        $this->assertThrows(fn () => $this->assist->question($this->by, $id, 'split'), EngineFailed::class);
        $this->assertSame('why left join null and also what is a right join', $questions->find($this->by, $id)->text);
    }

    public function test_a_selection_is_explained_shortened_or_fixed(): void
    {
        foreach (['explain', 'shorten', 'fix'] as $action) {
            $this->says("The result for {$action}.");
            $this->assertSame("The result for {$action}.", $this->assist->selection($this->by, 'A deadlock is when processes wait on each other for ever.', $action, $this->module));
            $this->assertStringContainsString('A deadlock is when processes wait', $this->engine->last()->messages[0]['content']);
            $this->assertStringContainsString(Assist::SELECTION[$action], $this->engine->last()->messages[0]['content']);
        }
        $this->assertThrows(fn () => $this->assist->selection($this->by, 'Text', 'translate'), NotFound::class);
    }

    public function test_a_folder_is_asked_where_its_things_belong_with_the_modules_listed(): void
    {
        app(Modules::class)->create($this->by, $this->workspace, ['title' => 'Week 3: Normalisation']);
        $folder = app(Folders::class)->create($this->by, 'workspace', $this->workspace, 'Unsorted');
        app(Files::class)->upload($this->by, 'folder', $folder->id, $this->temp("Joins.\n"), 'SQL joins slides.txt');
        app(Notes::class)->create($this->by, 'folder', $folder->id, 'Normal forms');
        $this->says("SQL joins slides.txt → Week 2: SQL joins\nNormal forms → Week 3: Normalisation");

        $words = $this->assist->where($this->by, $folder->id);

        $this->assertStringContainsString('Normal forms → Week 3: Normalisation', $words);
        $message = $this->engine->last()->messages[0]['content'];
        foreach (['The folder: Unsorted', '- SQL joins slides.txt', '- Normal forms', '- Week 2: SQL joins', '- Week 3: Normalisation'] as $part) {
            $this->assertStringContainsString($part, $message);
        }
        $empty = app(Folders::class)->create($this->by, 'workspace', $this->workspace, 'Empty');
        $this->assertSame('There is nothing in this folder yet.', $this->assist->where($this->by, $empty->id));
    }

    public function test_only_the_students_own_things_can_be_asked_about(): void
    {
        $bob = $this->principal($this->student());
        $theirs = app(Workspaces::class)->create($bob, ['name' => 'Private'])->id;
        $card = app(Flashcards::class)->add($bob, $theirs, null, 'Secret?', 'Secret.');
        $question = app(Questions::class)->ask($bob, $theirs, 'Secret?')->id;
        $folder = app(Folders::class)->create($bob, 'workspace', $theirs, 'Private folder')->id;

        $this->assertThrows(fn () => $this->assist->card($this->by, $card, 'improve'), NotFound::class);
        $this->assertThrows(fn () => $this->assist->question($this->by, $question, 'clarify'), NotFound::class);
        $this->assertThrows(fn () => $this->assist->where($this->by, $folder), NotFound::class);
        $this->assertSame([], $this->engine->requests);
    }

    public function test_the_parsers_cope_with_what_models_write(): void
    {
        $this->assertSame([['front' => 'A?', 'back' => 'B.']], Assist::cards("Front: A?\nBack: B."));
        $this->assertSame([['front' => 'A?', 'back' => 'B.'], ['front' => 'C?', 'back' => 'D.']], Assist::cards("Front: A?\nBack: B.\nFront: C?\nBack: D."));
        $this->assertSame(['One?', 'Two?'], Assist::lines("- Question 1: One?\n\n* Q2: Two?\n"));
    }
}
