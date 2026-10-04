<?php

namespace Tests\Feature\Web;

use App\Livewire\Workspaces\CardMaker;
use App\Livewire\Workspaces\Deck;
use App\Livewire\Workspaces\FlashcardEditor;
use App\Livewire\Workspaces\FlashcardReview;
use App\Livewire\Workspaces\Progress;
use App\Models\User;
use App\Study\Flashcards;
use App\Study\Modules;
use App\Study\TopicDetails;
use App\Study\Topics;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The Flashcards section, writing cards, making them with an AI, and reviewing (docs/specs/study-memory.md §4.5). */
class FlashcardScreensTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private WorkspaceDetails $databases;

    private TopicDetails $joins;

    private TopicDetails $keys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
        $this->ada = $this->student();
        $by = $this->principal($this->ada);
        $this->databases = app(Workspaces::class)->create($by, ['name' => 'Databases']);
        $this->joins = app(Topics::class)->create($by, $this->databases->id, 'Joins');
        $this->keys = app(Topics::class)->create($by, $this->databases->id, 'Keys');
    }

    public function test_the_section_starts_empty_and_then_shows_whats_due_by_topic(): void
    {
        $this->actingAs($this->ada)->get(route('workspaces.show', [$this->databases->id, 'flashcards']))
            ->assertOk()->assertSee('No flashcards yet')->assertSee('Make cards with an AI')->assertSee('Cards');

        $this->card($this->joins->id, 'What does a LEFT JOIN keep?', 'Every left row.');
        $this->card($this->keys->id, 'What is a primary key?', 'A column that names each row.');
        $this->card(null, 'What is SQL?', 'A language for databases.');

        $this->actingAs($this->ada)->get(route('workspaces.show', [$this->databases->id, 'flashcards']))
            ->assertOk()->assertSee('3 cards to review today')->assertSee('3 cards, 3 new.')
            ->assertSeeInOrder(['Joins', '1 card', 'What does a LEFT JOIN keep?', 'Keys', 'What is a primary key?', 'No topic', 'What is SQL?'])
            ->assertSee(route('workspaces.flashcards.review', $this->databases->id), false);

        $this->livewire(Deck::class)->set('topic', $this->keys->id)
            ->assertSee('1 card to review today in Keys')->assertSee('What is a primary key?')->assertDontSee('What is SQL?')
            ->set('topic', 'none')->assertSee('What is SQL?')->assertDontSee('What is a primary key?')
            ->set('topic', 'not-a-topic')->assertSet('topic', '')->assertSee('What is SQL?')
            ->call('editCard', 'some-id')->assertDispatched('flashcard-edit', id: 'some-id')
            ->call('deleteCard', 'some-id')->assertDispatched('flashcard-delete', id: 'some-id');
    }

    public function test_cards_are_written_one_after_another_changed_and_deleted(): void
    {
        $editor = $this->livewire(FlashcardEditor::class)
            ->call('create', $this->joins->id)->assertDispatched('flashcard-dialog-open')->assertSet('topicId', $this->joins->id)
            ->call('save')->assertHasErrors(['front', 'back'])->assertSee('Write the front.')
            ->set('front', 'What does a LEFT JOIN keep?')->set('back', 'Every left row.')
            ->call('save', true)->assertDispatched('flashcards-changed')->assertDispatched('flashcard-front-focus')
            ->assertSet('mode', 'new')->assertSet('front', '')->assertSet('topicId', $this->joins->id)->assertSee('1 card added.')
            ->set('front', 'What does an INNER JOIN keep?')->set('back', 'Matching rows.')
            ->call('save')->assertDispatched('flashcard-dialog-close')->assertSet('mode', null);

        $cards = app(Flashcards::class)->list($this->principal($this->ada), $this->databases->id);
        $this->assertSame(['What does an INNER JOIN keep?', 'What does a LEFT JOIN keep?'], array_map(fn ($c) => $c->front, $cards));

        $editor->call('edit', $cards[0]->id)->assertSet('front', 'What does an INNER JOIN keep?')->assertSee('Edit flashcard')
            ->set('back', 'Only rows that match on both sides.')->set('topicId', '')->call('save');
        $edited = app(Flashcards::class)->find($this->principal($this->ada), $cards[0]->id);
        $this->assertSame(['Only rows that match on both sides.', null, 2], [$edited->back, $edited->topicId, $edited->revision]);

        $editor->call('confirmDelete', $cards[0]->id)->assertSee('Delete this flashcard?')->assertSee('What does an INNER JOIN keep?')->call('save');
        $this->assertCount(1, app(Flashcards::class)->list($this->principal($this->ada), $this->databases->id));

        // Another student's card doesn't open, and the card can't be swapped for one.
        $bob = $this->principal($this->student());
        $theirs = app(Workspaces::class)->create($bob, ['name' => 'Private']);
        $secret = app(Flashcards::class)->add($bob, $theirs->id, null, 'Secret', 'Words');
        $editor->call('edit', $secret)->assertSet('mode', null)->assertDontSee('Secret');
        $this->assertThrows(fn () => $editor->call('create')->set('cardId', $secret), CannotUpdateLockedPropertyException::class);
    }

    public function test_cards_are_made_with_an_ai_from_a_prompt_and_its_pasted_reply(): void
    {
        $this->card($this->joins->id, 'What does a LEFT JOIN keep?', 'Every left row.');
        $reply = '<flashcard topic="Joins"><front>What does a LEFT JOIN keep?</front><back>Every left row.</back></flashcard>'
            .'<flashcard topic="Joins"><front>When is a RIGHT JOIN useful?</front><back>To keep every row of the right table.</back></flashcard>'
            .'<flashcard topic="Joins"><front>What does a CROSS JOIN make?</front><back>Every pair of rows.</back></flashcard>';

        $maker = $this->livewire(CardMaker::class)
            ->call('open', $this->joins->id)->assertDispatched('card-maker-dialog-open')
            ->assertSee('Copy the prompt into an AI')->assertSee('10 flashcards about Joins')
            ->set('count', 5)->assertSee('5 flashcards about Joins')
            ->call('save')->assertHasErrors('text')
            ->set('text', 'Sure! No cards here.')->call('save')->assertHasErrors('text')
            ->set('text', $reply)->call('save')->assertSet('step', 'review')
            ->assertSee('3 cards found.')->assertSee('Already a card')->assertSee('Add 2 cards');

        $maker->set('cards.2.include', false)->assertSee('Add 1 card')
            ->call('save')->assertDispatched('flashcards-changed')->assertDispatched('card-maker-dialog-close')
            ->assertSee('Added 1 card.');

        $fronts = array_map(fn ($c) => [$c->front, $c->author], app(Flashcards::class)->list($this->principal($this->ada), $this->databases->id, $this->joins->id));
        $this->assertSame([['When is a RIGHT JOIN useful?', 'ai'], ['What does a LEFT JOIN keep?', 'student']], $fronts);
    }

    public function test_a_round_of_review_turns_cards_over_brings_missed_ones_back_and_ends_with_a_summary(): void
    {
        $left = $this->card($this->joins->id, 'What does a LEFT JOIN keep?', 'Every left row.');
        $inner = $this->card($this->joins->id, 'What does an INNER JOIN keep?', 'Matching rows.');
        $this->card($this->keys->id, 'What is a primary key?', 'A column that names each row.');

        $this->actingAs($this->ada)->get(route('workspaces.flashcards.review', [$this->databases->id, 'topic' => $this->joins->id]))
            ->assertOk()->assertSee('Review')->assertSee('Card 1 of 2')->assertSee('What does a LEFT JOIN keep?')
            ->assertSee('Show the answer')->assertSee('Again this round')->assertSee('Back tomorrow')->assertDontSee('What is a primary key?');

        $review = $this->livewire(FlashcardReview::class, ['topicId' => $this->joins->id])
            ->assertSet('queue', [$left, $inner])
            ->assertSee('Back tomorrow')
            ->call('answer', 'correct')->assertSee('Card 2 of 2')->assertSee('What does an INNER JOIN keep?')
            ->call('answer', 'incorrect')->assertSee('Card 3 of 3')->assertSee('Another go at a card you missed.')
            ->call('answer', 'correct')
            ->assertSee('Round done')->assertSee('You went through 2 cards, and had another go at the ones you missed.')
            ->assertSeeInOrder(['1', 'Got it', '0', 'Partly', '1', 'Not yet'])
            ->assertSee('All caught up. Next up: 2 cards tomorrow.');

        $cards = collect(app(Flashcards::class)->list($this->principal($this->ada), $this->databases->id))->keyBy('id');
        $this->assertSame(['2026-10-06', 1, 1], [$cards[$left]->dueOn, $cards[$left]->step, $cards[$left]->reviews]);
        // The second go was recorded, and didn't undo "Not yet".
        $this->assertSame(['2026-10-06', 0, 2, 1], [$cards[$inner]->dueOn, $cards[$inner]->step, $cards[$inner]->reviews, $cards[$inner]->lapses]);

        // Nothing left for Joins: practise anyway, which moves nothing.
        $this->livewire(FlashcardReview::class, ['topicId' => $this->joins->id])
            ->assertSee('Nothing to review right now')->assertSee('Next up: 2 cards tomorrow.')
            ->call('again', true)->assertSee('Practising early')->assertDontSee('Back tomorrow')
            ->call('answer', 'incorrect')->call('answer', 'correct')->assertSee('Card 3 of 3');
        $this->assertSame('2026-10-06', app(Flashcards::class)->find($this->principal($this->ada), $inner)->dueOn);

        // The rest of the workspace is still due; the browser can't choose the cards.
        $all = $this->livewire(FlashcardReview::class)->assertSee('Card 1 of 1')->assertSee('What is a primary key?');
        $this->assertThrows(fn () => $all->set('queue', [$left]), CannotUpdateLockedPropertyException::class);
        $all->call('answer', 'nonsense')->assertSee('Card 1 of 1');
    }

    public function test_the_deck_is_organised_by_module_and_each_module_is_reviewed_on_its_own(): void
    {
        $by = $this->principal($this->ada);
        $week1 = app(Modules::class)->create($by, $this->databases->id, ['title' => 'Week 1: Relational model']);
        $week2 = app(Modules::class)->create($by, $this->databases->id, ['title' => 'Week 2: SQL joins']);
        app(Modules::class)->create($by, $this->databases->id, ['title' => 'Week 3: Normal forms']);
        app(Topics::class)->move($by, $this->joins->id, $week2->id);
        $this->card($this->joins->id, 'What does a LEFT JOIN keep?', 'Every left row.');
        app(Flashcards::class)->add($by, $this->databases->id, null, 'What is a relation?', 'A table.', moduleId: $week1->id);
        $this->card(null, 'What is SQL?', 'A language for databases.');

        // All modules: each with its cards and what's due, not every card.
        $this->actingAs($this->ada)->get(route('workspaces.show', [$this->databases->id, 'flashcards']))
            ->assertOk()->assertSee('3 cards to review today')
            ->assertSeeInOrder(['Week 1: Relational model', '1 card · 1 due', 'Week 2: SQL joins', '1 card · 1 due', 'Week 3: Normal forms', 'No cards yet', 'No module'])
            ->assertDontSee('What does a LEFT JOIN keep?')
            ->assertSee(route('workspaces.flashcards.review', [$this->databases->id, 'module' => $week1->id]), false)
            ->assertSee(route('workspaces.show', [$this->databases->id, 'flashcards', 'module' => $week2->id]), false);

        // One module: its cards by topic; a topic of another module is let go.
        $this->livewire(Deck::class)->set('module', $week2->id)
            ->assertSee('1 card to review today in Week 2: SQL joins')->assertSee('What does a LEFT JOIN keep?')->assertDontSee('What is a relation?')
            ->set('topic', $this->joins->id)->assertSee('in Week 2: SQL joins · Joins')
            ->set('module', $week1->id)->assertSet('topic', '')->assertSee('What is a relation?')->assertDontSee('What does a LEFT JOIN keep?')
            ->set('module', 'none')->assertSee('What is SQL?')->assertDontSee('What is a relation?')
            ->set('module', 'not-a-module')->assertSet('module', '')->assertDontSee('What is SQL?');

        // The editor puts a card in a module; a topic takes it to the topic's.
        $editor = $this->livewire(FlashcardEditor::class)->call('create', null, $week1->id)->assertSet('moduleId', $week1->id)
            ->assertSee('Week 2: SQL joins')
            ->set('topicId', $this->joins->id)->assertSet('moduleId', $week2->id)
            ->set('moduleId', $week1->id)->assertSet('topicId', '')
            ->set('front', 'What is a tuple?')->set('back', 'A row.')->call('save')->assertDispatched('flashcards-changed');
        $tuple = collect(app(Flashcards::class)->list($by, $this->databases->id))->firstWhere('front', 'What is a tuple?');
        $this->assertSame([null, $week1->id], [$tuple->topicId, $tuple->moduleId]);
        $editor->call('edit', $tuple->id)->assertSet('moduleId', $week1->id)->set('moduleId', '')->call('save');
        $this->assertNull(app(Flashcards::class)->find($by, $tuple->id)->moduleId);

        // A module's review holds only its cards, and leads back to it.
        $this->actingAs($this->ada)->get(route('workspaces.flashcards.review', [$this->databases->id, 'module' => $week1->id]))
            ->assertOk()->assertSee('Week 1: Relational model')->assertSee('What is a relation?')->assertSee('Card 1 of 1');
        $this->actingAs($this->ada)->get(route('workspaces.flashcards.review', [$this->databases->id, 'module' => 'not-a-module']))
            ->assertOk()->assertSee('of 4');

        // The module's page leads to its cards.
        $this->actingAs($this->ada)->get(route('workspaces.modules.show', [$this->databases->id, $week2->id]))
            ->assertOk()->assertSee('Flashcards')->assertSee(route('workspaces.show', [$this->databases->id, 'flashcards', 'module' => $week2->id]), false);
    }

    public function test_review_pages_belong_to_their_student(): void
    {
        $bob = $this->student();
        $this->actingAs($bob)->get(route('workspaces.flashcards.review', $this->databases->id))->assertNotFound();
        $this->actingAs($this->ada)->get(route('workspaces.flashcards.review', [$this->databases->id, 'topic' => 'not-a-topic']))
            ->assertOk()->assertSee('No cards here yet');
    }

    public function test_the_overview_progress_and_session_lead_to_the_cards(): void
    {
        $this->card($this->joins->id, 'What does a LEFT JOIN keep?', 'Every left row.');
        $this->card($this->joins->id, 'What does an INNER JOIN keep?', 'Matching rows.');

        $this->actingAs($this->ada)->get(route('workspaces.show', $this->databases->id))
            ->assertOk()->assertSee('2 cards due')
            ->assertSee(route('workspaces.flashcards.review', $this->databases->id), false);

        $this->livewire(Progress::class)
            ->assertSee('2 flashcards, 2 due')->assertSee('New flashcard')
            ->call('newFlashcard', $this->joins->id)->assertDispatched('flashcard-new', topicId: $this->joins->id);
    }

    private function card(?string $topicId, string $front, string $back): string
    {
        return app(Flashcards::class)->add($this->principal($this->ada), $this->databases->id, $topicId, $front, $back);
    }

    private function livewire(string $class, array $params = [])
    {
        $this->actingAs($this->ada);
        // Livewire's test requests skip middleware, so nothing gives them the session.
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        return Livewire::test($class, ['workspaceId' => $this->databases->id] + $params);
    }
}
