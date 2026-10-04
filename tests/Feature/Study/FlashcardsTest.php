<?php

namespace Tests\Feature\Study;

use App\Brain\Store\JournalReader;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\CardMaker;
use App\Study\Findings;
use App\Study\Flashcards;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\Sessions;
use App\Study\TopicDetails;
use App\Study\Topics;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsJournalEntries;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** Flashcards: writing, the review ladder, evidence, and making cards with an AI (docs/specs/study-memory.md §4.5). */
class FlashcardsTest extends TestCase
{
    use BuildsJournalEntries, CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private WorkspaceDetails $databases;

    private TopicDetails $joins;

    private TopicDetails $keys;

    private Flashcards $cards;

    protected function setUp(): void
    {
        parent::setUp();
        // 20:00 in London is 05:00 the next day in Tokyo: the student's day is what counts.
        $this->travelTo(CarbonImmutable::parse('2026-10-05 20:00:00', 'UTC'));
        $this->ada = $this->student();
        DB::table('learners')->where('user_id', $this->ada->id)->update(['timezone' => 'Asia/Tokyo']);
        $this->by = $this->principal($this->ada);
        $this->databases = app(Workspaces::class)->create($this->by, ['name' => 'Databases']);
        $this->joins = app(Topics::class)->create($this->by, $this->databases->id, 'Joins');
        $this->keys = app(Topics::class)->create($this->by, $this->databases->id, 'Keys');
        $this->cards = app(Flashcards::class);
    }

    public function test_a_card_is_a_task_that_exercises_its_topic_and_new_words_make_a_revision(): void
    {
        $id = $this->cards->add($this->by, $this->databases->id, $this->joins->id, '  What does a LEFT JOIN keep? ', 'Every left row.');
        $card = $this->cards->find($this->by, $id);
        $this->assertSame(['What does a LEFT JOIN keep?', 'student', 1, null, true], [$card->front, $card->author, $card->revision, $card->dueOn, $card->isNew()]);

        $tasks = $this->entries(fn ($e) => $e->kind->value === 'record' && ($e->body['record_type'] ?? null) === 'task');
        $this->assertSame(['card-'.$id, 'card/'.$id, 1, 'active'], [$tasks[0]->body['record_id'], $tasks[0]->body['key'], $tasks[0]->body['revision'], $tasks[0]->body['status']]);
        $this->assertSame([['task:card-'.$id, 'topic:'.$this->joins->id, 'active']], $this->exercises());

        // Same words, new topic: the claim moves, the task keeps its revision.
        $this->cards->update($this->by, $id, $this->keys->id, 'What does a LEFT JOIN keep?', 'Every left row.');
        $this->assertSame(1, $this->cards->find($this->by, $id)->revision);
        $this->assertSame([
            ['task:card-'.$id, 'topic:'.$this->joins->id, 'active'],
            ['task:card-'.$id, 'topic:'.$this->joins->id, 'retired'],
            ['task:card-'.$id, 'topic:'.$this->keys->id, 'active'],
        ], $this->exercises());

        // New words: a new revision of the same task.
        $this->cards->update($this->by, $id, $this->keys->id, 'What does a LEFT JOIN keep?', 'Every row of the left table.');
        $this->assertSame(2, $this->cards->find($this->by, $id)->revision);
        $tasks = $this->entries(fn ($e) => $e->kind->value === 'record' && ($e->body['record_type'] ?? null) === 'task');
        $this->assertSame([1, 2], array_map(fn ($e) => $e->body['revision'], $tasks));
        $this->assertNotSame($tasks[0]->body['content_hash'], $tasks[1]->body['content_hash']);
    }

    public function test_a_card_needs_both_sides_and_a_topic_of_its_workspace(): void
    {
        try {
            $this->cards->add($this->by, $this->databases->id, null, ' ', str_repeat('x', Flashcards::MAX_BACK + 1));
            $this->fail('A blank front was accepted.');
        } catch (Unprocessable $e) {
            $this->assertSame(['front', 'back'], array_keys($e->details['fields']));
        }

        $biology = app(Workspaces::class)->create($this->by, ['name' => 'Biology']);
        $this->expectException(NotFound::class);
        $this->cards->add($this->by, $biology->id, $this->joins->id, 'Front', 'Back');
    }

    public function test_the_ladder_waits_longer_after_each_got_it(): void
    {
        $this->assertSame([[1, 1], [2, 3], [3, 7], [7, 120], [7, 120]], array_map(fn ($step) => Flashcards::next($step, 'correct'), [0, 1, 2, 6, 7]));
        $this->assertSame([[0, 1], [3, 7], [7, 120]], array_map(fn ($step) => Flashcards::next($step, 'partial'), [0, 3, 7]));
        $this->assertSame([[0, 1], [0, 1]], array_map(fn ($step) => Flashcards::next($step, 'incorrect'), [0, 5]));
        $this->assertSame(['tomorrow', '3 days', '2 weeks', '1 month', '4 months'], array_map(fn ($d) => Flashcards::gapWords($d), [1, 3, 14, 30, 120]));
    }

    public function test_a_card_is_in_its_topics_module_else_the_one_chosen_else_its_sessions_and_the_deck_shows_them_by_module(): void
    {
        $modules = app(Modules::class);
        $week1 = $modules->create($this->by, $this->databases->id, ['title' => 'Week 1'])->id;
        $week2 = $modules->create($this->by, $this->databases->id, ['title' => 'Week 2'])->id;
        app(Topics::class)->move($this->by, $this->joins->id, $week2);
        $session = app(Sessions::class)->start($this->by, $this->databases->id, null, $week1);

        $onJoins = $this->cards->add($this->by, $this->databases->id, $this->joins->id, 'What does a LEFT JOIN keep?', 'Every left row.', moduleId: $week1);
        $chosen = $this->cards->add($this->by, $this->databases->id, null, 'What is a relation?', 'A table.', moduleId: $week1);
        $fromSession = $this->cards->add($this->by, $this->databases->id, null, 'What is a tuple?', 'A row.', 'ai', $session->id);
        $loose = $this->cards->add($this->by, $this->databases->id, null, 'What is SQL?', 'A query language.');
        $module = fn (string $id) => $this->cards->find($this->by, $id)->moduleId;
        $this->assertSame([$week2, $week1, $week1, null], [$module($onJoins), $module($chosen), $module($fromSession), $module($loose)]);
        $this->assertThrows(fn () => $this->cards->add($this->by, $this->databases->id, null, 'a', 'b', moduleId: Str::uuid()->toString()), NotFound::class);

        // By module: the cards, the counts and the reviews.
        $this->assertEqualsCanonicalizing([$chosen, $fromSession], array_column($this->cards->list($this->by, $this->databases->id, null, $week1), 'id'));
        $this->assertSame([$loose], array_column($this->cards->list($this->by, $this->databases->id, null, ''), 'id'));
        $this->assertEquals([$week1 => ['total' => 2, 'due' => 2, 'overdue' => 0], $week2 => ['total' => 1, 'due' => 1, 'overdue' => 0], '' => ['total' => 1, 'due' => 1, 'overdue' => 0]], $this->cards->counts($this->by, $this->databases->id)['modules']);
        $this->assertSame(1, $this->cards->counts($this->by, $this->databases->id, null, $week2)['total']);
        $this->assertSame([$onJoins], $this->cards->queue($this->by, $this->databases->id, null, false, $week2));

        // Changing the words keeps the module; '' takes the card out of every module; a topic's module wins.
        $this->cards->update($this->by, $chosen, null, 'What is a relation?', 'A table of rows.');
        $this->assertSame($week1, $module($chosen));
        $this->cards->update($this->by, $chosen, null, 'What is a relation?', 'A table of rows.', '');
        $this->assertNull($module($chosen));
        $this->cards->update($this->by, $chosen, $this->joins->id, 'What is a relation?', 'A table of rows.', $week1);
        $this->assertSame($week2, $module($chosen));

        // A topic that moves takes its cards along; a module that goes leaves them in none.
        app(Topics::class)->move($this->by, $this->joins->id, $week1);
        $this->assertSame([$week1, $week1], [$module($onJoins), $module($chosen)]);
        $modules->delete($this->by, $week1);
        $this->assertSame([null, null, null], [$module($onJoins), $module($chosen), $module($fromSession)]);
    }

    public function test_a_card_a_week_or_more_past_its_day_is_overdue_for_the_course_its_topic_and_its_module(): void
    {
        $week1 = app(Modules::class)->create($this->by, $this->databases->id, ['title' => 'Week 1'])->id;
        $late = $this->cards->add($this->by, $this->databases->id, $this->joins->id, 'Late?', 'Yes.', moduleId: $week1);
        $recent = $this->cards->add($this->by, $this->databases->id, $this->joins->id, 'Recent?', 'Yes.');
        $this->cards->add($this->by, $this->databases->id, $this->keys->id, 'New?', 'Yes.');
        // The student's day is 2026-10-06: a week before it is the 29th.
        DB::table('flashcards')->where('id', $late)->update(['due_on' => '2026-09-29']);
        DB::table('flashcards')->where('id', $recent)->update(['due_on' => '2026-09-30']);

        $counts = $this->cards->counts($this->by, $this->databases->id);
        $this->assertSame(1, $counts['overdue']);
        $this->assertSame(3, $counts['due']);
        $this->assertSame([1, 0], [$counts['topics'][$this->joins->id]['overdue'], $counts['topics'][$this->keys->id]['overdue']]);
        $this->assertSame(1, $counts['modules'][$this->joins->moduleId ?? $week1]['overdue']);
        $this->assertSame(7, Flashcards::OVERDUE_DAYS);
    }

    public function test_answers_move_cards_on_the_ladder_by_the_students_day_and_become_self_judged_attempts(): void
    {
        $left = $this->cards->add($this->by, $this->databases->id, $this->joins->id, 'What does a LEFT JOIN keep?', 'Every left row.');
        $inner = $this->cards->add($this->by, $this->databases->id, $this->joins->id, 'What does an INNER JOIN keep?', 'Matching rows only.');
        $this->assertSame('2026-10-06', $this->cards->today($this->by));
        $this->assertSame([$left, $inner], $this->cards->queue($this->by, $this->databases->id));
        $this->assertSame(['total' => 2, 'due' => 2, 'new' => 2, 'overdue' => 0, 'next_on' => null, 'next_count' => 0], array_diff_key($this->cards->counts($this->by, $this->databases->id), ['topics' => 1, 'modules' => 1]));

        $card = $this->cards->answer($this->by, $left, 'correct');
        $this->assertSame(['2026-10-07', 1, 1, 0, 'correct'], [$card->dueOn, $card->step, $card->reviews, $card->lapses, $card->lastResult]);
        $this->assertSame('Due tomorrow', $card->dueWords('2026-10-06'));
        $card = $this->cards->answer($this->by, $inner, 'incorrect');
        $this->assertSame(['2026-10-07', 0, 1], [$card->dueOn, $card->step, $card->lapses]);
        $this->assertSame([], $this->cards->queue($this->by, $this->databases->id));
        $this->assertSame([$left, $inner], $this->cards->queue($this->by, $this->databases->id, early: true));
        $this->assertSame(['due' => 0, 'next_on' => '2026-10-07', 'next_count' => 2], array_intersect_key($this->cards->counts($this->by, $this->databases->id), ['due' => 1, 'next_on' => 1, 'next_count' => 1]));

        // A second go in the round is recorded but moves nothing.
        $card = $this->cards->answer($this->by, $inner, 'correct', schedule: false);
        $this->assertSame(['2026-10-07', 0, 2], [$card->dueOn, $card->step, $card->reviews]);

        // The next day, in a study session: due again, and answered in that session.
        $this->travel(1)->days();
        $this->assertSame([$left, $inner], $this->cards->queue($this->by, $this->databases->id));
        $session = app(Sessions::class)->start($this->by, $this->databases->id, $this->joins->id);
        $card = $this->cards->answer($this->by, $left, 'correct');
        $this->assertSame(['2026-10-10', 2], [$card->dueOn, $card->step]);

        $attempts = $this->entries(fn ($e) => $e->kind->value === 'attempt');
        $this->assertSame(
            array_fill(0, 4, ['recall', 'unaided', 'practice', 'self']),
            array_map(fn ($e) => [$e->body['form'], $e->body['support'], $e->body['setting'], $e->body['judged_by']], $attempts),
        );
        $this->assertSame(['correct', 'incorrect', 'correct', 'correct'], array_map(fn ($e) => $e->body['outcome'], $attempts));
        $this->assertSame(['card-'.$left, 1], [$attempts[0]->body['task'], $attempts[0]->body['task_revision']]);
        // Reviews on a day with no study session share that day's session; in a session, they're in it.
        $this->assertMatchesRegularExpression('/^cards-2026-10-06-[0-9a-f]{16}$/', $attempts[0]->sessionId);
        $this->assertSame($attempts[0]->sessionId, $attempts[2]->sessionId);
        $this->assertSame($session->id, $attempts[3]->sessionId);
    }

    public function test_cards_saved_before_reviewing_existed_get_their_task_on_first_use(): void
    {
        $before = count($this->entries(fn () => true));
        $id = (string) Str::uuid7();
        DB::table('flashcards')->insert([
            'id' => $id, 'learner_id' => $this->learnerScopeOf($this->ada)->learnerId, 'workspace_id' => $this->databases->id,
            'topic_id' => $this->joins->id, 'front' => 'Front', 'back' => 'Back', 'author' => 'ai', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->cards->answer($this->by, $id, 'partial');

        $kinds = array_map(fn ($e) => $e->kind->value, array_slice($this->entries(fn () => true), $before));
        $this->assertSame(['record', 'claim', 'attempt'], $kinds);
    }

    public function test_a_deleted_card_leaves_reviews_and_its_task_is_retired(): void
    {
        $id = $this->cards->add($this->by, $this->databases->id, null, 'Front', 'Back');
        $this->cards->retire($this->by, $id);

        $this->assertSame([], $this->cards->list($this->by, $this->databases->id));
        $this->assertSame(0, $this->cards->counts($this->by, $this->databases->id)['total']);
        $tasks = $this->entries(fn ($e) => $e->kind->value === 'record' && ($e->body['record_type'] ?? null) === 'task');
        $this->assertSame(['active', 'retired'], array_map(fn ($e) => $e->body['status'], $tasks));
        $this->expectException(NotFound::class);
        $this->cards->answer($this->by, $id, 'correct');
    }

    public function test_another_students_cards_are_not_found(): void
    {
        $id = $this->cards->add($this->by, $this->databases->id, null, 'Front', 'Back');
        $grace = $this->principal($this->student());

        foreach ([
            fn () => $this->cards->find($grace, $id),
            fn () => $this->cards->answer($grace, $id, 'correct'),
            fn () => $this->cards->update($grace, $id, null, 'Mine', 'Now'),
            fn () => $this->cards->retire($grace, $id),
            fn () => $this->cards->list($grace, $this->databases->id),
            fn () => $this->cards->add($grace, $this->databases->id, null, 'Front', 'Back'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Another student reached the card.');
            } catch (NotFound) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_card_making_prompt_carries_the_material_and_the_reply_is_read_back(): void
    {
        app(Findings::class)->add($this->by, $this->joins->id, ['text' => 'A LEFT JOIN keeps every row of the left table.']);
        $note = app(Notes::class)->create($this->by, 'workspace', $this->databases->id, 'Lecture 3');
        app(Notes::class)->save($this->by, $note->id, [
            'base_version' => 1, 'save_id' => 'save-00000001', 'client_id' => 'client-00000001', 'title' => 'Lecture 3',
            'doc' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'An inner join keeps matching rows.']]]]],
        ]);
        $this->cards->add($this->by, $this->databases->id, $this->joins->id, 'What does a LEFT JOIN keep?', 'Every left row.');
        $maker = app(CardMaker::class);

        $prompt = $maker->prompt($this->by, $this->databases->id, $this->joins->id, 5, $note->id);
        $this->assertStringNotContainsString('<!--', $prompt);
        $this->assertStringContainsString('You are helping a student of Databases make flashcards.', $prompt);
        $this->assertStringContainsString('5 flashcards about Joins, from the material below.', $prompt);
        $this->assertStringContainsString('<flashcard topic="Joins"><front>The question</front>', $prompt);
        $this->assertStringContainsString("### Key points on Joins\n\n- A LEFT JOIN keeps every row of the left table.", $prompt);
        $this->assertStringContainsString("### The student's note: Lecture 3", $prompt);
        $this->assertStringContainsString('An inner join keeps matching rows.', $prompt);
        $this->assertStringContainsString("## Cards the student already has\n\n- What does a LEFT JOIN keep?", $prompt);
        $this->assertStringNotContainsString('{{', $prompt);

        // For all topics, the AI chooses among them; with no material, it says the cards are general.
        $all = $maker->prompt($this->by, $this->databases->id, null, 99);
        $this->assertStringContainsString('10 flashcards about the topics of Databases', $all);
        $this->assertStringContainsString('with its name exactly as written: "Joins", "Keys"', $all);
        $bare = $maker->prompt($this->by, $this->databases->id, $this->keys->id, 10);
        $this->assertStringContainsString('The student hasn\'t shared material for this yet.', $bare);

        $reply = <<<'REPLY'
            Here are your cards:
            ```
            <flashcard topic="Joins"><front>What does a LEFT JOIN keep?</front><back>Every left row.</back></flashcard>
            <flashcard topic="keys"><front>What is a primary key?</front><back>A column that names each row.</back></flashcard>
            <flashcard topic="Networks"><front>What is a packet?</front><back>A unit of data.</back></flashcard>
            <finding topic="Joins">Not a card.</finding>
            ```
            REPLY;
        $cards = $maker->read($this->by, $this->databases->id, $reply);
        $this->assertSame([
            [$this->joins->id, true, false],
            [$this->keys->id, false, true],
            ['', false, true],
        ], array_map(fn ($c) => [$c['topic_id'], $c['known'], $c['include']], $cards));
        $this->assertSame([$this->joins->id, $this->joins->id, $this->joins->id], array_column($maker->read($this->by, $this->databases->id, $reply, $this->joins->id), 'topic_id'));

        $cards[1]['back'] = 'A column whose value names each row.';
        $cards[2]['topic_id'] = 'not-a-topic';
        $result = $maker->save($this->by, $this->databases->id, $cards);
        $this->assertSame(['saved' => 1, 'failed' => [[2, 'Its topic or module no longer exists.']]], $result);
        $saved = $this->cards->list($this->by, $this->databases->id, $this->keys->id)[0];
        $this->assertSame(['A column whose value names each row.', 'ai', null], [$saved->back, $saved->author, $saved->sessionId]);
    }

    /** @return list<object> the student's journal entries that pass $keep, in order */
    private function entries(callable $keep): array
    {
        return array_values(array_filter(app(JournalReader::class)->entries($this->learnerScopeOf($this->ada)), $keep));
    }

    /** @return list<array{0: string, 1: string, 2: string}> the exercises claims, in order */
    private function exercises(): array
    {
        return array_map(fn ($e) => [...$e->body['targets'], $e->body['value']['status']], $this->entries(
            fn ($e) => $e->kind->value === 'claim' && $e->body['type'] === 'relates' && $e->body['value']['relation'] === 'exercises',
        ));
    }
}
