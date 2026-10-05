<?php

namespace App\Livewire\Workspaces;

use App\Appearance\Icons;
use App\Engine\ChatMarks;
use App\Engine\Choices;
use App\Engine\EngineFailed;
use App\Engine\Models;
use App\Engine\SessionChat;
use App\Engine\Settings;
use App\Engine\Toolbox;
use App\Identity\PrincipalFactory;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\Files;
use App\Study\FileTypes;
use App\Study\FolderDetails;
use App\Study\Folders;
use App\Study\MarkdownPreview;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\SessionDetails;
use App\Study\Sessions;
use App\Study\TopicDetails;
use App\Study\Topics;
use Closure;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The built-in chat on a study session's page (docs/specs/study-memory.md §6): the student's turns and the
 * tutor's, the look-ups each answer made and what the chat has cost against the limit, a box to write in, and
 * a way to keep what the tutor marked (the write-back's review, opened on the chat's replies). Notes and files
 * from the module (the session's chosen material first; the folder's, in a folder's session) can be attached to a
 * message, and a file or a picture uploaded, pasted or dropped into the box goes into the "From the chat" folder of
 * the session's folder, or else of its module, first. The answer
 * streams in as the tutor writes it, with what it is looking up meanwhile; a turn the engine failed on can be
 * tried again. A thin adapter over App\Engine\SessionChat; the engine's refusals show as a line under the box.
 */
final class TutorChat extends Component
{
    public const SUGGESTIONS = [
        'Where did we stop last time, and what should we do today?',
        'Explain this topic to me simply, one part at a time.',
        'Quiz me on what I should know by now.',
    ];

    /** The chips above the box: one tap asks the tutor for the thing, so nothing is typed. */
    public const ACTIONS = [
        'cards' => ['Cards', 'gallery-vertical-end', 'Make flashcards of what we just covered.'],
        'note' => ['Note this', 'notebook-pen', 'Write what we just covered in my study note.'],
        'where' => ['Where are we', 'flag', 'Where are we? What is done and what is left?'],
    ];

    public const TEST_ASK = 'Test me on this whole module: ten exam-level questions, one at a time, and score me at the end.';

    /** How often the answer so far is sent to the browser while it streams, in seconds. */
    private const EVERY = 0.08;

    #[Locked]
    public string $workspaceId;

    #[Locked]
    public string $sessionId;

    public string $text = '';

    #[Locked]
    public ?string $error = null;

    private SessionChat $chat;

    private Settings $settings;

    private Sessions $sessions;

    private PrincipalFactory $principals;

    private ChatStream $stream;

    private Notes $notes;

    private Files $files;

    private Topics $topics;

    private Models $models;

    private Modules $modules;

    private Folders $folders;

    public function boot(SessionChat $chat, Settings $settings, Sessions $sessions, PrincipalFactory $principals, ChatStream $stream, Notes $notes, Files $files, Topics $topics, Models $models, Modules $modules, Folders $folders): void
    {
        [$this->notes, $this->files, $this->topics, $this->models, $this->modules, $this->folders] = [$notes, $files, $topics, $models, $modules, $folders];
        $this->chat = $chat;
        $this->settings = $settings;
        $this->sessions = $sessions;
        $this->principals = $principals;
        $this->stream = $stream;
    }

    public function mount(string $workspaceId, string $sessionId): void
    {
        [$this->workspaceId, $this->sessionId] = [$workspaceId, $sessionId];
        // A session that ended without its wrap-up (it ended by itself, or the engine was down) gets it once, here.
        $by = $this->principal();
        $session = $this->sessions->find($by, $sessionId);
        // A quiz or a test starts with its ask waiting in the message box, for the student to send (Study this ▾ on the
        // module page, or `?ask=`): the tutor asks nothing before they do.
        $ask = request()->query('ask');
        $ask = in_array($ask, ['quiz', 'test'], true) ? $ask : (in_array($session->mode, ['quiz', 'test'], true) && $this->chat->transcript($by, $sessionId) === [] ? $session->mode : null);
        // A question taken to the tutor (✦ on a question): free mode, with the question waiting in the box.
        $say = trim((string) request()->query('say'));
        if ($session->isOpen() && request()->query('ask') === 'free' && $session->mode === 'free' && $say !== '' && $this->chat->transcript($by, $sessionId) === []) {
            $this->text = mb_substr($say, 0, 500);
        }
        if ($session->isOpen() && $ask !== null) {
            $this->text = $ask === 'quiz'
                ? ($this->quizzes($by, $session)[0]['text'] ?? 'Quiz me on what I find hardest in this course.')
                : self::TEST_ASK;
        }
        if (! $session->isOpen()) {
            try {
                $this->chat->wrapUp($by, $sessionId);
            } catch (Unprocessable|NotFound) {
                // Nothing to wrap up, or not now.
            }
        }
    }

    /** Sends the student's words (given by the page, or in $text) with what they attached, and streams the answer. */
    public function send(?string $text = null, mixed $attach = []): void
    {
        $words = trim($text ?? $this->text);
        if ($words === '' && (! is_array($attach) || $attach === [])) {
            $this->dispatch('chat-done', restore: null);

            return;
        }
        $this->turn(fn (Closure $on) => $this->chat->send($this->principal(), $this->sessionId, $words, $on, $attach), $words);
    }

    /** One of the suggested openings, or a quiz from the Quiz me menu. */
    public function say(string $text): void
    {
        $by = $this->principal();
        if (! in_array($text, [...self::SUGGESTIONS, self::TEST_ASK, ...array_column(self::ACTIONS, 2), ...array_column($this->quizzes($by, $this->sessions->find($by, $this->sessionId)), 'text')], true)) {
            $this->dispatch('chat-done', restore: null);

            return;
        }
        $this->send($text);
    }

    /** Answers the last message again, after the engine failed on it. */
    public function retry(): void
    {
        $this->turn(fn (Closure $on) => $this->chat->retry($this->principal(), $this->sessionId, $on));
    }

    /** Opens the write-back's review on everything the tutor marked so far. */
    public function keep(): void
    {
        $texts = [];
        foreach ($this->chat->transcript($this->principal(), $this->sessionId) as $turn) {
            if ($turn['role'] === 'assistant' && $turn['text'] !== '') {
                $texts[] = $turn['text'];
            }
        }
        $this->dispatch('capture-open', text: implode("\n\n", $texts));
    }

    /** The tutor's proposed status for a topic, accepted with a tap: from now on it is the student's word. */
    public function applyStatus(string $topicId, string $status): void
    {
        $by = $this->principal();
        $this->sessions->find($by, $this->sessionId);
        if (! in_array($status, Topics::STATUSES, true)) {
            return;
        }
        try {
            $this->topics->report($by, $topicId, $status);
        } catch (NotFound) {
            return;
        }
        $this->dispatch('session-changed');
    }

    /** Takes back a status the tutor set: the topic goes back to what it was, unless the student has said something since. */
    public function undoStatus(string $topicId): void
    {
        $by = $this->principal();
        $last = $this->chat->activity($by, $this->sessionId)['statuses'][$topicId] ?? null;
        if (! is_array($last) || ($last['applied'] ?? false) !== true || ! is_array($last['before'] ?? null)) {
            return;
        }
        try {
            $this->topics->unmark($by, $topicId, $last['before']);
        } catch (NotFound) {
            return;
        }
        $this->dispatch('session-changed');
    }

    public function render(): View
    {
        $by = $this->principal();
        $session = $this->sessions->find($by, $this->sessionId);
        $choices = $this->settings->get($by);
        $turns = $this->chat->transcript($by, $this->sessionId);
        $spent = $this->chat->spent($by, $this->sessionId);
        $ready = $this->settings->keyAvailable($by) && $choices->ready();
        $marked = false;
        $hasStatuses = array_filter($turns, fn (array $t) => ($t['statuses'] ?? []) !== []) !== [];
        $topicsNow = $hasStatuses ? array_column($this->topics->list($by, $session->workspaceId), null, 'id') : [];
        foreach ($turns as &$turn) {
            $turn['attachments'] = array_map(fn (array $a) => $a + ['url' => $this->urlOf($a['ref']), 'icon' => self::ICONS[$a['kind']] ?? 'file'], $turn['attachments']);
            if ($turn['role'] === 'assistant') {
                $presented = ChatMarks::present($turn['text']);
                $marked = $marked || $presented !== $turn['text'];
                $turn['html'] = MarkdownPreview::html($presented);
                $turn['looked'] = array_values(array_unique(array_map(Toolbox::words(...), array_filter($turn['tools'], fn (string $tool) => ! Toolbox::writes($tool)))));
                $turn['savedWords'] = self::savedWords($turn['saved'] ?? []);
                $turn['notes'] = array_map(fn (array $note) => $note + ['url' => route('workspaces.notes.show', [$this->workspaceId, $note['id'], 'window' => 1])], $turn['notes'] ?? []);
                $turn['marks'] = self::marks($turn['statuses'] ?? [], $topicsNow);
            }
        }
        unset($turn);

        return view('livewire.workspaces.tutor-chat', [
            'open' => $session->isOpen(),
            'ready' => $ready,
            'choices' => $choices,
            'turns' => $turns,
            'marked' => $marked,
            'waiting' => $session->isOpen() && $ready && $this->chat->waiting($by, $this->sessionId),
            'spentWords' => Choices::dollars($spent['session']).($spent['session_cap'] > 0 ? ' of '.Choices::dollars($spent['session_cap']) : '').' this session',
            'suggestions' => self::SUGGESTIONS,
            'actions' => self::ACTIONS,
            'testAsk' => self::TEST_ASK,
            'mode' => $session->mode,
            'hasModule' => $this->moduleOf($by, $session) !== null,
            'attachable' => $ready && $session->isOpen() ? $this->attachable($by, $session) : ['module' => null, 'items' => []],
            'account' => (string) auth()->id(),
            'upload' => [
                'url' => route('api.v1.files.store'),
                // Into the session's folder (its "From the chat" folder), else the module's, else the course's.
                'type' => ($folder = $this->folderOf($by, $session)) !== null ? 'folder' : (($module = $this->moduleOf($by, $session)) !== null ? 'module' : 'workspace'),
                'id' => $folder?->id ?? $module ?? $session->workspaceId,
                'maxBytes' => Files::maxBytes(),
                'accept' => implode(',', array_map(fn (string $extension) => ".{$extension}", array_keys(FileTypes::TYPES))),
                'icons' => array_map(fn (string $icon) => Icons::url($icon), self::ICONS),
            ],
            'quizzes' => $ready && $session->isOpen() ? $this->quizzes($by, $session) : [],
            'sees' => ($model = $this->models->find($choices->tutorModel, $this->settings->key($by))) === null || $model->images,
        ]);
    }

    /**
     * The statuses the tutor set or proposed in a turn, as chips that say where each stands now: marked by the tutor (with
     * an undo), accepted (the student's word now), taken back, or still only a suggestion (with a tap to accept it).
     *
     * @param  list<array<string, mixed>>  $statuses
     * @param  array<string, TopicDetails>  $topics
     * @return list<array{topic_id: string, topic: string, to: string, words: string, state: string, reason: ?string}>
     */
    private static function marks(array $statuses, array $topics): array
    {
        $words = ['covered' => 'covered', 'understood' => 'understood', 'confused' => 'still confusing'];
        $marks = [];
        foreach ($statuses as $s) {
            $now = $topics[$s['topic_id']] ?? null;
            if ($now === null || ! isset($words[$s['to']])) {
                continue;
            }
            $state = match (true) {
                $s['applied'] && $now->status === $s['to'] && $now->byTutor() => 'marked',
                $now->status === $s['to'] => 'accepted',
                $s['applied'] => 'undone',
                default => 'proposed',
            };
            $marks[] = ['topic_id' => $s['topic_id'], 'topic' => $s['topic'], 'to' => $s['to'], 'words' => $words[$s['to']], 'state' => $state, 'reason' => $s['reason'] ?? null];
        }

        return $marks;
    }

    /** "3 flashcards, 1 key point, 2 new topics": what a turn saved in the course. */
    public static function savedWords(array $saved): ?string
    {
        $words = ['flashcard' => ['flashcard', 'flashcards'], 'finding' => ['key point', 'key points'], 'question' => ['question', 'questions'], 'topic' => ['new topic', 'new topics']];
        $parts = [];
        foreach ($words as $kind => [$one, $many]) {
            if (($saved[$kind] ?? 0) > 0) {
                $parts[] = $saved[$kind].' '.($saved[$kind] === 1 ? $one : $many);
            }
        }

        return $parts === [] ? null : implode(', ', $parts);
    }

    /** The icon of each kind of attachment. */
    private const ICONS = ['note' => 'notebook-pen', 'file' => 'file-text', 'picture' => 'file-image'];

    /**
     * What can be attached: the session's chosen material first, then the rest of its folder's notes and files (docs/
     * specs/vistud-2-blueprint.md, Phase 9), else its module's (the course's, when the session has no module).
     *
     * @return array{module: ?string, items: list<array{ref: string, name: string, kind: string, chosen: bool}>}
     */
    private function attachable(Principal $by, SessionDetails $session): array
    {
        $module = $this->moduleOf($by, $session);
        $folder = $this->folderOf($by, $session);
        $inside = $folder === null ? null : $this->folders->within($by, $folder->id);
        $here = fn (?string $itemModule, ?string $itemFolder) => match (true) {
            $inside !== null => $itemFolder !== null && in_array($itemFolder, $inside, true),
            default => $module === null || $itemModule === $module,
        };
        $items = [];
        foreach ($this->notes->list($by, $session->workspaceId) as $note) {
            if ($here($note->moduleId, $note->folderId) || $session->uses("note:{$note->id}")) {
                $items[] = ['ref' => "note:{$note->id}", 'name' => $note->displayTitle(), 'kind' => 'note', 'chosen' => $session->uses("note:{$note->id}")];
            }
        }
        foreach ($this->files->list($by, $session->workspaceId) as $file) {
            if ($here($file->moduleId, $file->folderId) || $session->uses("file:{$file->id}")) {
                $items[] = ['ref' => "file:{$file->id}", 'name' => $file->fileName(), 'kind' => $file->kind === 'image' ? 'picture' : 'file', 'chosen' => $session->uses("file:{$file->id}")];
            }
        }
        usort($items, fn ($a, $b) => [$b['chosen'], $a['name']] <=> [$a['chosen'], $b['name']]);

        return ['module' => $module, 'items' => array_slice($items, 0, 80)];
    }

    /**
     * What the Quiz me menu offers: the session's topic, its module, and what the student finds hardest.
     *
     * @return list<array{label: string, text: string}>
     */
    private function quizzes(Principal $by, SessionDetails $session): array
    {
        $quizzes = [];
        if ($session->topicId !== null) {
            try {
                $topic = $this->topics->find($by, $session->topicId)->name;
                $quizzes[] = ['label' => "On {$topic}", 'text' => "Quiz me on {$topic}."];
            } catch (NotFound) {
                // Removed since.
            }
        }
        if (($folder = $this->folderOf($by, $session)) !== null) {
            $quizzes[] = ['label' => "On {$folder->name}", 'text' => "Quiz me on the folder {$folder->name}."];
        }
        if (($module = $this->moduleOf($by, $session)) !== null) {
            try {
                $title = $this->modules->find($by, $module)->title;
                $quizzes[] = ['label' => "On {$title}", 'text' => "Quiz me on the module {$title}."];
            } catch (NotFound) {
                // Deleted since.
            }
        }
        $quizzes[] = ['label' => 'On what I find hardest', 'text' => 'Quiz me on what I find hardest in this course.'];

        return $quizzes;
    }

    /** The session's module: its own, or its topic's. */
    /** The folder the session studies in, while it is there. */
    private function folderOf(Principal $by, SessionDetails $session): ?FolderDetails
    {
        if ($session->folderId === null) {
            return null;
        }
        try {
            return $this->folders->find($by, $session->folderId);
        } catch (NotFound) {
            return null;
        }
    }

    private function moduleOf(Principal $by, SessionDetails $session): ?string
    {
        if ($session->moduleId !== null || $session->topicId === null) {
            return $session->moduleId;
        }
        try {
            return $this->topics->find($by, $session->topicId)->moduleId;
        } catch (NotFound) {
            return null;
        }
    }

    private function urlOf(string $ref): ?string
    {
        [$kind, $id] = explode(':', $ref, 2) + [1 => ''];

        return match ($kind) {
            'note' => route('workspaces.notes.show', [$this->workspaceId, $id]),
            'file' => route('workspaces.files.show', [$this->workspaceId, $id]),
            default => null,
        };
    }

    /**
     * Runs one turn, streaming the answer so far (with any half-written mark held back) and what the tutor is
     * looking up. Words the chat refused go back to the box; once kept, an engine that fails leaves them in the
     * chat, to try again.
     */
    private function turn(Closure $run, ?string $words = null): void
    {
        $this->error = null;
        // A long answer with look-ups can outlast PHP's usual 30 seconds, and leaving the page mid-answer
        // shouldn't lose it: it is kept, to read on return.
        @set_time_limit(300);
        ignore_user_abort(true);
        $answer = '';
        $sent = 0.0;
        $changed = [];
        $on = function (string $kind, string $value) use (&$answer, &$sent, &$changed) {
            if ($kind === 'looking') {
                $this->stream->push($this, 'status', e(Toolbox::doing($value)));

                return;
            }
            if ($kind === 'saved') {
                $effects = json_decode($value, true);
                // Notes the tutor wrote in: an editor open on one is told to show the new version.
                foreach ($effects['notes'] ?? [] as $note) {
                    $changed[$note['id']] = ['id' => (string) $note['id'], 'version' => (int) $note['version']];
                }
                // A new topic for the session: the session page shows it.
                if (isset($effects['topic'])) {
                    $this->dispatch('session-changed');
                }

                return;
            }
            if ($answer === '') {
                $this->stream->push($this, 'status', '');
            }
            $answer .= $value;
            if (microtime(true) - $sent >= self::EVERY) {
                $sent = microtime(true);
                $this->stream->push($this, 'answer', MarkdownPreview::html(ChatMarks::present(ChatMarks::partial($answer))));
            }
        };

        try {
            $run($on);
        } catch (Unprocessable $e) {
            $this->announce($changed);
            // A field's own words ("Write something first.") over the general ones.
            $fields = is_array($e->details['fields'] ?? null) ? $e->details['fields'] : [];
            $first = $fields !== [] ? (array_values($fields)[0][0] ?? null) : null;
            $this->error = is_string($first) ? $first : $e->getMessage();
            $this->dispatch('chat-done', restore: $words ?? '');

            return;
        } catch (EngineFailed $e) {
            $this->announce($changed);
            $this->error = $e->getMessage();
            $this->text = '';
            $this->dispatch('chat-done', restore: null);

            return;
        }
        $this->announce($changed);
        $this->text = '';
        $this->dispatch('chat-turn');
        $this->dispatch('chat-done', restore: null);
    }

    /**
     * Tells the page what the tutor changed: open notes show their new version (resources/js/tutor-chat.js passes
     * it on to the editor), and the question board looks again.
     *
     * @param  array<string, array{id: string, version: int}>  $notes
     */
    private function announce(array $notes): void
    {
        if ($notes !== []) {
            $this->dispatch('notes-changed', notes: array_values($notes));
        }
        $this->dispatch('questions-changed');
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
