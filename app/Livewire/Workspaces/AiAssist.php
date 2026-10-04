<?php

namespace App\Livewire\Workspaces;

use App\Engine\Assist;
use App\Engine\Jobs\AnswerFromNotes;
use App\Engine\Jobs\CardsFrom;
use App\Engine\Jobs\NoteFromFile;
use App\Engine\Jobs\ReadFile;
use App\Engine\Jobs\Runner;
use App\Identity\PrincipalFactory;
use App\Livewire\Concerns\Notices;
use App\Platform\Access\Principal;
use App\Platform\Errors\AppError;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\NotFound;
use App\Study\FileDigestDetails;
use App\Study\FileDigests;
use App\Study\Files;
use App\Study\Flashcards;
use App\Study\Notes;
use App\Study\Questions;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\TopicSuggestions;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The ✦ menu's one sheet (docs/specs/vistud-2-blueprint.md §3.6.5, Phase 5): what a card, a question, a selection of a note,
 * a file or a folder is offered, run by the helper or the reader, shown as a proposal, and kept only when the student says
 * so. A ✦ menu (<x-ai-menu>) sends `ai-assist` with what to do and to which; the sheet opens, asks, and shows either the
 * before and after (Keep or Discard), the cards to tick, or what was made. Nothing is applied silently, and nothing is kept
 * of an exchange that isn't. The ids it acts on are locked and every read and write goes through the services, so only the
 * student's own can be reached.
 */
final class AiAssist extends Component
{
    use Notices;

    /** What each action is called on the sheet. */
    public const TITLES = [
        'card.improve' => 'Improve this card', 'card.shorter' => 'A shorter card', 'card.fix' => 'Fix the wording', 'card.more' => 'Two more like this',
        'question.clarify' => 'Clarify the question', 'question.split' => 'Split into two', 'question.answer' => 'Answer from my notes',
        'note.explain' => 'Explain this', 'note.shorten' => 'Shorten this', 'note.fix' => 'Fix this text',
        'file.summarise' => 'What this file says', 'file.note' => 'Make a note from it', 'file.cards' => 'Make cards from it', 'file.topics' => 'Find topics',
        'note.cards' => 'Make cards from it', 'topic.cards' => 'Make cards from it', 'folder.where' => 'Where should these go?', 'cards.pick' => 'Make cards from…',
    ];

    #[Locked]
    public string $workspaceId;

    /** One of TITLES' keys, or null when the sheet is closed. */
    #[Locked]
    public ?string $action = null;

    /** The card, question, file, note, topic or folder it is about. */
    #[Locked]
    public ?string $targetId = null;

    /** choose (what to make cards from), working, result or failed; null when closed. */
    #[Locked]
    public ?string $step = null;

    /** Counts up with each start, so a new request is a new sheet body. */
    #[Locked]
    public int $round = 0;

    /** The text a selection action is about (a selection of a note). */
    #[Locked]
    public string $text = '';

    /** @var array<string, mixed> what was proposed, as the view shows it and keep() applies it */
    #[Locked]
    public array $proposal = [];

    #[Locked]
    public ?string $error = null;

    /** @var list<array{front: string, back: string, topic_id: ?string, topic: ?string, include: bool}> the cards to review, as the student edits them */
    public array $cards = [];

    public string $sourceType = 'topic';

    public string $sourceId = '';

    public int $count = 8;

    private Assist $assist;

    private Runner $runner;

    private Flashcards $flashcards;

    private Questions $questions;

    private Notes $notes;

    private Files $files;

    private FileDigests $digests;

    private Topics $topics;

    private Sessions $sessions;

    private TopicSuggestions $suggestions;

    private PrincipalFactory $principals;

    public function boot(Assist $assist, Runner $runner, Flashcards $flashcards, Questions $questions, Notes $notes, Files $files, FileDigests $digests, Topics $topics, Sessions $sessions, TopicSuggestions $suggestions, PrincipalFactory $principals): void
    {
        $this->sessions = $sessions;
        $this->assist = $assist;
        $this->runner = $runner;
        $this->flashcards = $flashcards;
        $this->questions = $questions;
        $this->notes = $notes;
        $this->files = $files;
        $this->digests = $digests;
        $this->topics = $topics;
        $this->suggestions = $suggestions;
        $this->principals = $principals;
    }

    public function mount(string $workspaceId): void
    {
        $this->workspaceId = $workspaceId;
    }

    /** A ✦ menu's choice: what to do (`card.improve`…), to which, and for a selection, the text. */
    #[On('ai-assist')]
    public function start(string $action, string $id = '', string $text = ''): void
    {
        if (! isset(self::TITLES[$action])) {
            return;
        }
        $this->reset('proposal', 'error', 'cards');
        $this->round++;
        [$this->action, $this->targetId, $this->text] = [$action, $id === '' ? null : $id, mb_substr($text, 0, 6_000)];

        if ($action === 'cards.pick') {
            // What to start from, when the page knows (a topic the deck is showing); else the student chooses.
            $this->step = 'choose';
            $this->sourceType = in_array($id, CardsFrom::TYPES, true) ? $id : 'topic';
            $this->sourceId = $this->text;
            $this->text = '';
            $this->targetId = null;
        } else {
            $this->step = 'working';
        }
        $this->dispatch('ai-sheet-open');
    }

    /** The question taken to the tutor: a session in free mode in the question's module, with the question waiting in the box. */
    #[On('ai-ask-tutor')]
    public function askTheTutor(string $id): void
    {
        $by = $this->principal();
        $question = $this->questions->find($by, $id);
        $question->workspaceId === $this->workspaceId || throw new NotFound;
        try {
            $last = $this->sessions->lastChoices($by, $this->workspaceId);
            $session = $this->sessions->start($by, $this->workspaceId, $question->topicId, $question->moduleId, $last['pomodoro'], $last['tutoring'], 'free');
        } catch (Conflict) {
            $this->notify('Another session is open. End it first, or go back to it.', 'warning');

            return;
        }
        $this->dispatch('session-changed');
        $this->redirectRoute('workspaces.sessions.show', [$this->workspaceId, $session->id, 'ask' => 'free', 'say' => mb_substr($question->text, 0, 500)], navigate: true);
    }

    /** The student chose what to make cards from: ask the reader. */
    public function chosen(): void
    {
        $this->resetErrorBag();
        if ($this->sourceId === '') {
            $this->addError('sourceId', 'Choose what to make cards from.');

            return;
        }
        $this->action = ['file' => 'file.cards', 'note' => 'note.cards'][$this->sourceType] ?? 'topic.cards';
        $this->targetId = $this->sourceId;
        $this->step = 'working';
        $this->round++;
    }

    /** Asks the helper or the reader, and keeps what comes back as a proposal. The sheet shows it working while this runs. */
    public function run(): void
    {
        if ($this->step !== 'working' || $this->action === null) {
            return;
        }
        $by = $this->principal();
        try {
            $this->proposal = $this->propose($by);
            $this->step = 'result';
        } catch (AppError $e) {
            [$this->error, $this->step] = [$e->getMessage(), 'failed'];
        }
    }

    /** The student keeps what was proposed. */
    public function keep(): void
    {
        if ($this->step !== 'result') {
            return;
        }
        $by = $this->principal();
        try {
            $this->notice = $this->apply($by);
        } catch (AppError $e) {
            [$this->error, $this->step] = [$e->getMessage(), 'failed'];

            return;
        }
        $this->close();
        $this->dispatch('ai-sheet-close');
    }

    /** The note that was made, taken away again. */
    public function removeNote(): void
    {
        $id = (string) ($this->proposal['note_id'] ?? '');
        if ($id !== '' && ($this->proposal['kind'] ?? null) === 'note') {
            $this->notes->trash($this->principal(), $id);
            $this->notify('The note is in the trash.');
        }
        $this->close();
        $this->dispatch('ai-sheet-close');
    }

    public function close(): void
    {
        $this->reset('action', 'targetId', 'step', 'text', 'proposal', 'error', 'cards', 'sourceId');
        $this->resetErrorBag();
    }

    public function render(): View
    {
        $by = $this->principal();
        $choose = $this->step === 'choose';

        return view('livewire.workspaces.ai-assist', [
            'title' => self::TITLES[$this->action ?? ''] ?? 'AI',
            'topics' => $choose ? $this->topics->list($by, $this->workspaceId) : [],
            'notes' => $choose ? array_values(array_filter($this->notes->list($by, $this->workspaceId), fn ($note) => $note->trashedAt === null)) : [],
            'files' => $choose ? array_values(array_filter($this->files->list($by, $this->workspaceId), fn ($file) => $file->trashedAt === null && $file->kind !== 'image')) : [],
            'ticked' => count(array_filter($this->cards, fn (array $card) => $card['include'] ?? false)),
            'counts' => CardsFrom::COUNTS,
        ]);
    }

    // ---------- What is asked ----------

    /** @return array<string, mixed> */
    private function propose(Principal $by): array
    {
        $id = (string) $this->targetId;
        [$kind, $verb] = explode('.', (string) $this->action, 2);

        return match (true) {
            $kind === 'card' && $verb === 'more' => ['kind' => 'cards-add'] + $this->assist->card($by, $id, $verb),
            $kind === 'card' => ['kind' => 'card'] + $this->assist->card($by, $id, $verb),
            $this->action === 'question.answer' => ['kind' => 'answer'] + $this->runner->answer($by, new AnswerFromNotes($this->workspaceId, $id)),
            $kind === 'question' => ['kind' => 'question', 'verb' => $verb] + $this->assist->question($by, $id, $verb),
            $kind === 'note' && $verb !== 'cards' => ['kind' => 'text', 'verb' => $verb, 'text' => $this->assist->selection($by, $this->text, $verb, null), 'before' => $this->text],
            $this->action === 'file.summarise' => $this->summary($by, $id),
            $this->action === 'file.topics' => $this->findTopics($by, $id),
            $this->action === 'file.note' => $this->madeNote($by, $id),
            $this->action === 'folder.where' => ['kind' => 'words', 'text' => $this->assist->where($by, $id)],
            default => $this->makeCards($by, $kind, $id),
        };
    }

    /** @return array<string, mixed> */
    private function makeCards(Principal $by, string $type, string $id): array
    {
        $cards = $this->runner->answer($by, new CardsFrom($this->workspaceId, $type, $id, $this->count));
        $this->cards = array_map(fn (array $card) => $card + ['include' => true], $cards);

        return ['kind' => 'cards', 'type' => $type, 'id' => $id];
    }

    /** @return array<string, mixed> */
    private function madeNote(Principal $by, string $id): array
    {
        $note = $this->runner->answer($by, new NoteFromFile($this->workspaceId, $id));

        return ['kind' => 'note', 'note_id' => $note->id, 'title' => $note->displayTitle(), 'url' => route('workspaces.notes.show', [$note->workspaceId, $note->id])];
    }

    /**
     * What the reader made of the file: read now if it hasn't been.
     *
     * @return array<string, mixed>
     */
    private function summary(Principal $by, string $id): array
    {
        $digest = $this->read($by, $id);
        if ($digest === null || ! $digest->read()) {
            return ['kind' => 'words', 'text' => 'There are no words in this file to read.'];
        }

        return ['kind' => 'digest', 'summary' => $digest->summary, 'topics' => $digest->topics, 'outline' => array_slice($digest->outline, 0, 12)];
    }

    /**
     * The topics in a file, as suggestions waiting on its module: the file is read first if it hasn't been.
     *
     * @return array<string, mixed>
     */
    private function findTopics(Principal $by, string $id): array
    {
        $file = $this->files->find($by, $id);
        $file->workspaceId === $this->workspaceId || throw new NotFound;
        $digest = $this->read($by, $id);
        if ($digest === null || ! $digest->read()) {
            return ['kind' => 'words', 'text' => 'There are no words in this file to find topics in.'];
        }
        if ($file->moduleId === null) {
            return ['kind' => 'topics', 'names' => $digest->topics, 'found' => 0, 'url' => null, 'module' => null];
        }
        $found = $this->suggestions->suggest($by, $file->moduleId, $file->id, $digest->topics);

        return ['kind' => 'topics', 'names' => $digest->topics, 'found' => $found, 'url' => route('workspaces.modules.show', [$this->workspaceId, $file->moduleId, 'tab' => 'topics']), 'module' => $file->moduleId];
    }

    private function read(Principal $by, string $fileId): ?FileDigestDetails
    {
        $file = $this->files->find($by, $fileId);
        $file->workspaceId === $this->workspaceId || throw new NotFound;
        if (($digest = $this->digests->find($by, $fileId)) === null) {
            $this->runner->now($by, new ReadFile($this->workspaceId, $fileId));
            $digest = $this->digests->find($by, $fileId);
        }

        return $digest;
    }

    // ---------- What is kept ----------

    private function apply(Principal $by): string
    {
        $proposal = $this->proposal;
        $id = (string) $this->targetId;

        switch ($proposal['kind'] ?? null) {
            case 'card':
                $card = $this->flashcards->find($by, $id);
                $this->flashcards->update($by, $id, $card->topicId, $proposal['cards'][0]['front'] ?? '', $proposal['cards'][0]['back'] ?? '');
                $this->dispatch('flashcards-changed');

                return 'The card is changed.';
            case 'cards-add':
                $card = $this->flashcards->find($by, $id);
                foreach ($proposal['cards'] ?? [] as $new) {
                    $this->flashcards->add($by, $card->workspaceId, $card->topicId, $new['front'], $new['back'], 'ai', null, $card->moduleId);
                }
                $this->dispatch('flashcards-changed');
                $n = count($proposal['cards'] ?? []);

                return $n === 1 ? 'Added 1 card.' : "Added {$n} cards.";
            case 'question':
                $question = $this->questions->find($by, $id);
                $lines = $proposal['questions'] ?? [];
                $this->questions->update($by, $id, $lines[0] ?? '');
                if (($proposal['verb'] ?? '') === 'split' && isset($lines[1])) {
                    $this->questions->ask($by, $question->workspaceId, $lines[1], $question->topicId, $question->moduleId);
                }
                $this->dispatch('questions-changed');

                return ($proposal['verb'] ?? '') === 'split' ? 'The question is now two.' : 'The question is clearer.';
            case 'answer':
                $from = $proposal['from'] ?? [];
                $this->questions->setStatus($by, $id, 'answered', trim(($proposal['answer'] ?? '').' (From your notes'.($from === [] ? '' : ': '.implode(', ', $from)).'.)'));
                $this->dispatch('questions-changed');

                return 'The answer is kept on the question.';
            case 'text':
                $this->dispatch('ai-assist-keep', verb: $proposal['verb'] ?? '', text: $proposal['text'] ?? '');

                return ($proposal['verb'] ?? '') === 'explain' ? 'The explanation is added below.' : 'The text is changed.';
            case 'cards':
                return $this->addCards($by, $proposal);
            default:
                return '';
        }
    }

    /** @param  array<string, mixed>  $proposal */
    private function addCards(Principal $by, array $proposal): string
    {
        $moduleId = null;
        if (($proposal['type'] ?? null) === 'topic') {
            $moduleId = $this->topics->find($by, (string) $proposal['id'])->moduleId;
        } elseif (($proposal['type'] ?? null) === 'file') {
            $moduleId = $this->files->find($by, (string) $proposal['id'])->moduleId;
        } elseif (($proposal['type'] ?? null) === 'note') {
            $moduleId = $this->notes->find($by, (string) $proposal['id'])->moduleId;
        }
        $added = 0;
        foreach ($this->cards as $card) {
            if (! ($card['include'] ?? false) || trim((string) ($card['front'] ?? '')) === '') {
                continue;
            }
            $topicId = is_string($card['topic_id'] ?? null) && $card['topic_id'] !== '' ? $card['topic_id'] : null;
            $this->flashcards->add($by, $this->workspaceId, $topicId, $card['front'], $card['back'] ?? '', 'ai', null, $topicId === null ? $moduleId : null);
            $added++;
        }
        $this->dispatch('flashcards-changed');

        return $added === 0 ? 'No cards were added.' : ($added === 1 ? 'Added 1 card.' : "Added {$added} cards.").' They are due for their first review now.';
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }

    /** A card's front, short, for the views. */
    public static function short(string $text): string
    {
        return Str::limit($text, 80);
    }
}
