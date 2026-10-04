<?php

namespace Tests\Feature\Web;

use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\Settings;
use App\Livewire\Workspaces\AiAssist;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Study\FileDigests;
use App\Study\Files;
use App\Study\Flashcards;
use App\Study\Folders;
use App\Study\MarkdownDoc;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\Questions;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\TopicSuggestions;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\MakesStudyFiles;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The ✦ menu: each path with a fake reply, shown as a proposal, kept or discarded (docs/specs/vistud-2-blueprint.md §3.6.5). */
class AiMenuScreenTest extends TestCase
{
    use CreatesAccounts, MakesStudyFiles, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private string $workspace;

    private string $module;

    private string $topic;

    private Fake $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 09:00:00', 'UTC'));
        $this->engine = new Fake;
        $this->app->instance(Engine::class, $this->engine);
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->workspace = app(Workspaces::class)->create($this->by, ['name' => 'Operating Systems'])->id;
        $this->module = app(Modules::class)->create($this->by, $this->workspace, ['title' => 'Week 3'])->id;
        $this->topic = app(Topics::class)->create($this->by, $this->workspace, 'CPU scheduling', $this->module)->id;
        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/plain', 'helper_model' => 'fake/plain', 'consent' => true]);
    }

    private function sheet()
    {
        $this->actingAs($this->ada);
        // Livewire's test requests skip middleware, so nothing gives them the session.
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        return Livewire::test(AiAssist::class, ['workspaceId' => $this->workspace]);
    }

    private function says(string $text): void
    {
        $this->engine->will(Fake::says($text, 1_000, 'fake/plain'));
    }

    private function card(): string
    {
        return app(Flashcards::class)->add($this->by, $this->workspace, $this->topic, 'Can you tell me what a quantum is?', 'It is the slice.');
    }

    public function test_a_card_is_improved_shown_before_and_after_and_changed_only_when_kept(): void
    {
        $id = $this->card();
        $this->says("Front: What is a quantum?\nBack: The fixed slice of CPU time a process gets in round robin.");

        $sheet = $this->sheet()->dispatch('ai-assist', action: 'card.improve', id: $id)
            ->assertSet('step', 'working')->assertDispatched('ai-sheet-open')->assertSee('Improve this card')->assertSee('Working on it')
            ->call('run')->assertSet('step', 'result')->assertSee('Before')->assertSee('After')
            ->assertSee('Can you tell me what a quantum is?')->assertSee('What is a quantum?')->assertSee('Keep')->assertSee('Discard');
        $this->assertSame('Can you tell me what a quantum is?', app(Flashcards::class)->find($this->by, $id)->front, 'nothing changed yet');

        $sheet->call('keep')->assertDispatched('flashcards-changed')->assertDispatched('ai-sheet-close')->assertSet('step', null)->assertSee('The card is changed.');
        $card = app(Flashcards::class)->find($this->by, $id);
        $this->assertSame(['What is a quantum?', 'The fixed slice of CPU time a process gets in round robin.', $this->topic], [$card->front, $card->back, $card->topicId]);
    }

    public function test_discarding_leaves_the_card_as_it_was(): void
    {
        $id = $this->card();
        $this->says("Front: Shorter?\nBack: Yes.");

        $this->sheet()->dispatch('ai-assist', action: 'card.shorter', id: $id)->call('run')->assertSet('step', 'result')->call('close')->assertSet('step', null)->assertSet('proposal', []);
        $this->assertSame('Can you tell me what a quantum is?', app(Flashcards::class)->find($this->by, $id)->front);
    }

    public function test_two_more_like_this_are_added_to_the_cards_topic_as_the_ais(): void
    {
        $id = $this->card();
        $this->says("Front: What is round robin?\nBack: Each process gets a quantum in turn.\n\nFront: What is starvation?\nBack: Never getting the CPU.");

        $this->sheet()->dispatch('ai-assist', action: 'card.more', id: $id)->call('run')->assertSee('What is round robin?')->assertSee('Add 2 cards')
            ->call('keep')->assertSee('Added 2 cards.');
        $cards = app(Flashcards::class)->list($this->by, $this->workspace);
        $this->assertCount(3, $cards);
        $added = array_values(array_filter($cards, fn ($c) => $c->author === 'ai'));
        $this->assertSame([$this->topic, $this->topic], array_column($added, 'topicId'));
    }

    public function test_a_question_is_clarified_or_split_and_answered_from_the_notes(): void
    {
        $questions = app(Questions::class);
        $id = $questions->ask($this->by, $this->workspace, 'quantum what and why long bad', $this->topic, $this->module)->id;
        $this->says('Why is a long quantum bad for short jobs?');
        $this->sheet()->dispatch('ai-assist', action: 'question.clarify', id: $id)->call('run')->assertSee('Use this')->call('keep')->assertDispatched('questions-changed');
        $this->assertSame('Why is a long quantum bad for short jobs?', $questions->find($this->by, $id)->text);

        $this->says("What is a quantum?\nWhy is a long quantum bad?");
        $this->sheet()->dispatch('ai-assist', action: 'question.split', id: $id)->call('run')->assertSee('Two questions')->assertSee('Use the two')->call('keep')->assertSee('The question is now two.');
        $texts = array_map(fn ($q) => $q->text, $questions->list($this->by, $this->workspace));
        $this->assertEqualsCanonicalizing(['What is a quantum?', 'Why is a long quantum bad?'], $texts);
        $second = collect($questions->list($this->by, $this->workspace))->firstWhere('text', 'Why is a long quantum bad?');
        $this->assertSame([$this->topic, $this->module], [$second->topicId, $second->moduleId]);

        // From the notes: the reader's answer is kept on the question, saying where it is from.
        $notes = app(Notes::class);
        $note = $notes->create($this->by, 'module', $this->module, 'Lecture 3 notes');
        $notes->append($this->by, $note->id, MarkdownDoc::blocks('A quantum is the fixed slice of CPU time a process gets.'));
        $this->says(json_encode(['found' => true, 'answer' => 'The fixed slice of CPU time.', 'from' => ['Lecture 3 notes']]));
        $this->sheet()->dispatch('ai-assist', action: 'question.answer', id: $id)->call('run')->assertSee('From your notes: Lecture 3 notes.')->assertSee('Keep as the answer')
            ->call('keep')->assertSee('The answer is kept on the question.');
        $kept = $questions->find($this->by, $id);
        $this->assertSame('answered', $kept->status);
        $this->assertStringContainsString('The fixed slice of CPU time. (From your notes: Lecture 3 notes.)', (string) $kept->answer);
    }

    public function test_notes_that_do_not_answer_say_so_on_the_sheet_and_nothing_is_kept(): void
    {
        $id = app(Questions::class)->ask($this->by, $this->workspace, 'What is a semaphore?', null, $this->module)->id;

        $this->sheet()->dispatch('ai-assist', action: 'question.answer', id: $id)->call('run')
            ->assertSet('step', 'failed')->assertSee('There are no notes or files in this module to answer from yet.')->assertSee('Try again')->assertSee('open the AI settings');
        $this->assertSame('pending', app(Questions::class)->find($this->by, $id)->status);
    }

    public function test_a_question_taken_to_the_tutor_starts_a_free_session_with_it_waiting_and_a_second_is_refused(): void
    {
        $id = app(Questions::class)->ask($this->by, $this->workspace, 'Why can round robin starve a long job?', $this->topic, $this->module)->id;

        $this->sheet()->call('askTheTutor', $id)->assertDispatched('session-changed')
            ->assertRedirect(route('workspaces.sessions.show', [$this->workspace, app(Sessions::class)->current($this->by)->id, 'ask' => 'free', 'say' => 'Why can round robin starve a long job?']));
        $session = app(Sessions::class)->current($this->by);
        $this->assertSame(['free', $this->topic, $this->module], [$session->mode, $session->topicId, $session->moduleId]);

        // The chat opens with the question in the box, for the student to send.
        $this->actingAs($this->ada)->get(route('workspaces.sessions.show', [$this->workspace, $session->id, 'ask' => 'free', 'say' => 'Why can round robin starve a long job?']))
            ->assertOk()->assertSee('Why can round robin starve a long job?');
        // With a session open, another can't start.
        $this->sheet()->call('askTheTutor', $id)->assertNotDispatched('session-changed')->assertSee('Another session is open');
    }

    public function test_a_selection_is_explained_and_kept_by_the_editor_not_the_server(): void
    {
        $this->says('A deadlock is when processes wait on each other for ever.');

        $this->sheet()->dispatch('ai-assist', action: 'note.explain', text: 'Deadlock needs four Coffman conditions.')->call('run')
            ->assertSee('A deadlock is when processes wait')->assertSee('Add below')
            ->call('keep')->assertDispatched('ai-assist-keep', verb: 'explain', text: 'A deadlock is when processes wait on each other for ever.');
        $this->assertStringContainsString('Deadlock needs four Coffman conditions.', $this->engine->last()->messages[0]['content']);

        $this->says('Four conditions cause a deadlock.');
        $this->sheet()->dispatch('ai-assist', action: 'note.shorten', text: 'Deadlock needs four Coffman conditions.')->call('run')->assertSee('Replace the text')
            ->call('keep')->assertDispatched('ai-assist-keep', verb: 'shorten', text: 'Four conditions cause a deadlock.');
    }

    public function test_a_file_is_summarised_read_first_if_it_was_not_and_its_topics_wait_in_the_module(): void
    {
        $file = app(Files::class)->upload($this->by, 'module', $this->module, $this->temp("Scheduling decides which process runs next.\n"), 'Lecture 3.txt');
        $this->says(json_encode(['summary' => 'How the CPU picks the next process.', 'outline' => [['page' => 1, 'heading' => 'Scheduling']], 'topics' => ['Round robin', 'Priorities'], 'language' => 'English']));

        $this->sheet()->dispatch('ai-assist', action: 'file.summarise', id: $file->id)->call('run')
            ->assertSee('How the CPU picks the next process.')->assertSee('Round robin · Priorities')->assertSee('Scheduling');
        $this->assertTrue(app(FileDigests::class)->find($this->by, $file->id)->read());
        $this->assertCount(1, $this->engine->requests);

        // Already read: no second call, and the topics go to the module as suggestions, not as topics.
        $this->sheet()->dispatch('ai-assist', action: 'file.topics', id: $file->id)->call('run')->assertSee('Nothing new')->assertSee('Round robin, Priorities');
        $this->assertCount(1, $this->engine->requests);
        $waiting = app(TopicSuggestions::class)->list($this->by, $this->module);
        $this->assertSame(['Round robin', 'Priorities'], array_map(fn ($s) => $s->name, $waiting), 'the reading itself put them there');
        $this->assertCount(1, app(Topics::class)->list($this->by, $this->workspace));
    }

    public function test_a_note_is_made_from_a_file_and_can_be_taken_away_again(): void
    {
        $file = app(Files::class)->upload($this->by, 'module', $this->module, $this->temp("Round robin gives each process a quantum.\n"), 'Lecture 3.txt');
        $this->says("## Round robin\n\n- Each process gets a **quantum** in turn.");

        $sheet = $this->sheet()->dispatch('ai-assist', action: 'file.note', id: $file->id)->call('run')
            ->assertSee('Made the note')->assertSee('Notes · Lecture 3')->assertSee('Open it')->assertSee('Remove it');
        $made = collect(app(Notes::class)->list($this->by, $this->workspace))->firstWhere('title', 'Notes · Lecture 3');
        $this->assertSame($this->module, $made->moduleId);

        $sheet->call('removeNote')->assertSee('The note is in the trash.')->assertSet('step', null);
        $this->assertNotNull(app(Notes::class)->find($this->by, $made->id)->trashedAt);
    }

    public function test_cards_from_a_file_are_ticked_edited_and_only_the_ticked_are_added(): void
    {
        $file = app(Files::class)->upload($this->by, 'module', $this->module, $this->temp("Round robin gives each process a quantum. Starvation is never getting the CPU.\n"), 'Lecture 3.txt');
        $this->says(json_encode(['cards' => [
            ['front' => 'What does round robin give each process?', 'back' => 'A quantum, in turn.', 'topic' => 'CPU scheduling'],
            ['front' => 'What is starvation?', 'back' => 'Never getting the CPU.'],
            ['front' => 'A card to untick', 'back' => 'Not wanted.'],
        ]]));

        $sheet = $this->sheet()->dispatch('ai-assist', action: 'file.cards', id: $file->id)->call('run')
            ->assertSee('Untick the ones you do not want')->assertSee('Add 3 cards')->assertSet('cards.0.include', true);
        $this->assertSame([], app(Flashcards::class)->list($this->by, $this->workspace), 'nothing is added until the student keeps them');

        $sheet->set('cards.2.include', false)->assertSee('Add 2 cards')->set('cards.1.back', 'Never getting the CPU, for ever.')->call('keep')
            ->assertDispatched('flashcards-changed')->assertSee('Added 2 cards.');
        $cards = collect(app(Flashcards::class)->list($this->by, $this->workspace))->sortBy('front')->values();
        $this->assertSame(['What does round robin give each process?', 'What is starvation?'], $cards->pluck('front')->all());
        $this->assertSame(['ai', 'ai'], $cards->pluck('author')->all());
        // The one that names a topic is on it; the other is in the file's module.
        $this->assertSame([$this->topic, null], $cards->pluck('topicId')->all());
        $this->assertSame([$this->module, $this->module], $cards->pluck('moduleId')->all());
        $this->assertSame('Never getting the CPU, for ever.', $cards[1]->back);
    }

    public function test_make_cards_from_asks_what_from_and_how_many_before_asking_the_ai(): void
    {
        $note = app(Notes::class)->create($this->by, 'module', $this->module, 'Scheduling notes');
        app(Notes::class)->append($this->by, $note->id, MarkdownDoc::blocks('Round robin rotates through ready processes.'));

        $sheet = $this->sheet()->dispatch('ai-assist', action: 'cards.pick')->assertSet('step', 'choose')->assertSee('Make cards from…')->assertSee('A topic')->assertSee('A note')->assertSee('A file')->assertSee('CPU scheduling')
            ->call('chosen')->assertHasErrors('sourceId')->assertSet('step', 'choose');
        $this->assertSame([], $this->engine->requests);

        $this->says(json_encode(['cards' => [['front' => 'What does round robin do?', 'back' => 'Rotates through ready processes.']]]));
        $sheet->call('$set', 'sourceType', 'note')->assertSee('Scheduling notes')->set('sourceId', $note->id)->set('count', 5)->call('chosen')
            ->assertSet('step', 'working')->assertSet('action', 'note.cards')->call('run')->assertSee('Add 1 card')->call('keep')->assertSee('Added 1 card.');
        $this->assertStringContainsString('Make 5 flashcards from the note "Scheduling notes"', $this->engine->last()->messages[0]['content']);
        $this->assertCount(1, app(Flashcards::class)->list($this->by, $this->workspace));
    }

    public function test_where_the_things_in_a_folder_belong_is_read_not_applied(): void
    {
        $folder = app(Folders::class)->create($this->by, 'workspace', $this->workspace, 'Unsorted');
        app(Files::class)->upload($this->by, 'folder', $folder->id, $this->temp("Scheduling.\n"), 'Scheduling slides.txt');
        $this->says('Scheduling slides.txt → Week 3');

        $this->sheet()->dispatch('ai-assist', action: 'folder.where', id: $folder->id)->call('run')->assertSee('Scheduling slides.txt → Week 3')->assertSee('Close')->assertDontSee('Keep');
        $this->assertSame($folder->id, app(Files::class)->list($this->by, $this->workspace)[0]->folderId, 'nothing was moved');
    }

    public function test_a_failed_call_says_so_with_a_way_on_and_is_never_applied(): void
    {
        $id = $this->card();
        $this->says('Sure! Here you go.');

        $this->sheet()->dispatch('ai-assist', action: 'card.improve', id: $id)->call('run')->assertSet('step', 'failed')->assertSee('couldn\'t be used')->assertSee('Try again')
            ->call('keep')->assertSet('step', 'failed');
        $this->assertSame('Can you tell me what a quantum is?', app(Flashcards::class)->find($this->by, $id)->front);
    }

    public function test_another_students_things_are_not_found_and_the_sheet_only_takes_what_it_knows(): void
    {
        $bob = $this->principal($this->student());
        $theirs = app(Workspaces::class)->create($bob, ['name' => 'Private'])->id;
        $card = app(Flashcards::class)->add($bob, $theirs, null, 'Secret?', 'Secret.');

        $this->sheet()->dispatch('ai-assist', action: 'card.improve', id: $card)->call('run')->assertSet('step', 'failed');
        $this->assertSame([], $this->engine->requests);
        $this->sheet()->dispatch('ai-assist', action: 'card.delete', id: $card)->assertSet('step', null)->assertNotDispatched('ai-sheet-open');
    }

    public function test_the_browser_cannot_change_what_the_sheet_acts_on(): void
    {
        foreach (['workspaceId', 'action', 'targetId', 'step', 'proposal', 'text', 'round'] as $property) {
            $this->assertThrows(fn () => $this->sheet()->set($property, 'other'), CannotUpdateLockedPropertyException::class);
        }
    }

    public function test_the_pages_that_offer_the_menu_have_it_and_the_sheet(): void
    {
        $this->card();
        $question = app(Questions::class)->ask($this->by, $this->workspace, 'Why?', $this->topic, $this->module)->id;
        app(Folders::class)->create($this->by, 'module', $this->module, 'Lectures');
        $file = app(Files::class)->upload($this->by, 'module', $this->module, $this->temp("Words.\n"), 'Words.txt');
        $note = app(Notes::class)->create($this->by, 'module', $this->module, 'Notes');
        $this->actingAs($this->ada);

        $this->get(route('workspaces.show', [$this->workspace, 'flashcards']))->assertOk()->assertSeeLivewire(AiAssist::class)->assertSee('AI help with Can you tell me what a quantum is?')->assertSee('Two more like this');
        $this->get(route('workspaces.questions.show', [$this->workspace, $question]))->assertOk()->assertSeeLivewire(AiAssist::class)->assertSee('AI help with Why?')->assertSee('Answer from my notes')->assertSee('Ask the tutor');
        $this->get(route('workspaces.modules.show', [$this->workspace, $this->module, 'tab' => 'files']))->assertOk()->assertSeeLivewire(AiAssist::class)
            ->assertSee('AI help with Words.txt')->assertSee('Make a note from it')->assertSee('Find topics')->assertSee('AI help with Lectures')->assertSee('Where should these go?');
        $this->get(route('workspaces.modules.show', [$this->workspace, $this->module, 'tab' => 'notes']))->assertOk()->assertSee('AI help with Notes');
        $this->get(route('workspaces.files.show', [$this->workspace, $file->id]))->assertOk()->assertSeeLivewire(AiAssist::class)->assertSee('AI help with Words.txt');
        $this->get(route('workspaces.notes.show', [$this->workspace, $note->id]))->assertOk()->assertSeeLivewire(AiAssist::class)->assertSee('id="note-ai-menu"', false)->assertSee('Fix the text');
        $this->get(route('workspaces.show', [$this->workspace, 'progress']))->assertOk()->assertSeeLivewire(AiAssist::class);
    }
}
