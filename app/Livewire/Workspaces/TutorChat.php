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
use App\Study\MarkdownPreview;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\SessionDetails;
use App\Study\Sessions;
use App\Study\Topics;
use Closure;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The built-in chat on a study session's page (docs/specs/study-memory.md §6): the student's turns and the
 * tutor's, the look-ups each answer made and what the chat has cost against the limit, a box to write in, and
 * a way to keep what the tutor marked (the write-back's review, opened on the chat's replies). Notes and files
 * from the module (the session's chosen material first) can be attached to a message, and a file or a picture
 * uploaded, pasted or dropped into the box goes into the module's "From the chat" folder first. The answer
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

    public function boot(SessionChat $chat, Settings $settings, Sessions $sessions, PrincipalFactory $principals, ChatStream $stream, Notes $notes, Files $files, Topics $topics, Models $models, Modules $modules): void
    {
        [$this->notes, $this->files, $this->topics, $this->models, $this->modules] = [$notes, $files, $topics, $models, $modules];
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
        if (! $this->sessions->find($by, $sessionId)->isOpen()) {
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
        if (! in_array($text, [...self::SUGGESTIONS, ...array_column($this->quizzes($by, $this->sessions->find($by, $this->sessionId)), 'text')], true)) {
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

    public function render(): View
    {
        $by = $this->principal();
        $session = $this->sessions->find($by, $this->sessionId);
        $choices = $this->settings->get($by);
        $turns = $this->chat->transcript($by, $this->sessionId);
        $spent = $this->chat->spent($by, $this->sessionId);
        $ready = $this->settings->keyAvailable($by) && $choices->ready();
        $marked = false;
        foreach ($turns as &$turn) {
            $turn['attachments'] = array_map(fn (array $a) => $a + ['url' => $this->urlOf($a['ref']), 'icon' => self::ICONS[$a['kind']] ?? 'file'], $turn['attachments']);
            if ($turn['role'] === 'assistant') {
                $presented = ChatMarks::present($turn['text']);
                $marked = $marked || $presented !== $turn['text'];
                $turn['html'] = MarkdownPreview::html($presented);
                $turn['looked'] = array_values(array_unique(array_map(Toolbox::words(...), $turn['tools'])));
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
            'attachable' => $ready && $session->isOpen() ? $this->attachable($by, $session) : ['module' => null, 'items' => []],
            'upload' => [
                'url' => route('api.v1.files.store'),
                'type' => ($module = $this->moduleOf($by, $session)) !== null ? 'module' : 'workspace',
                'id' => $module ?? $session->workspaceId,
                'maxBytes' => Files::maxBytes(),
                'accept' => implode(',', array_map(fn (string $extension) => ".{$extension}", array_keys(FileTypes::TYPES))),
                'icons' => array_map(fn (string $icon) => Icons::url($icon), self::ICONS),
            ],
            'quizzes' => $ready && $session->isOpen() ? $this->quizzes($by, $session) : [],
            'sees' => ($model = $this->models->find($choices->tutorModel, $this->settings->key($by))) === null || $model->images,
        ]);
    }

    /** The icon of each kind of attachment. */
    private const ICONS = ['note' => 'notebook-pen', 'file' => 'file-text', 'picture' => 'file-image'];

    /**
     * What can be attached: the session's chosen material first, then the rest of its module's notes and files
     * (the course's, when the session has no module).
     *
     * @return array{module: ?string, items: list<array{ref: string, name: string, kind: string, chosen: bool}>}
     */
    private function attachable(Principal $by, SessionDetails $session): array
    {
        $module = $this->moduleOf($by, $session);
        $items = [];
        foreach ($this->notes->list($by, $session->workspaceId) as $note) {
            if ($module === null || $note->moduleId === $module || $session->uses("note:{$note->id}")) {
                $items[] = ['ref' => "note:{$note->id}", 'name' => $note->displayTitle(), 'kind' => 'note', 'chosen' => $session->uses("note:{$note->id}")];
            }
        }
        foreach ($this->files->list($by, $session->workspaceId) as $file) {
            if ($module === null || $file->moduleId === $module || $session->uses("file:{$file->id}")) {
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
        $on = function (string $kind, string $value) use (&$answer, &$sent) {
            if ($kind === 'looking') {
                $this->stream->push($this, 'status', e('Looking up '.Toolbox::words($value).'…'));

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
            // A field's own words ("Write something first.") over the general ones.
            $fields = is_array($e->details['fields'] ?? null) ? $e->details['fields'] : [];
            $first = $fields !== [] ? (array_values($fields)[0][0] ?? null) : null;
            $this->error = is_string($first) ? $first : $e->getMessage();
            $this->dispatch('chat-done', restore: $words ?? '');

            return;
        } catch (EngineFailed $e) {
            $this->error = $e->getMessage();
            $this->text = '';
            $this->dispatch('chat-done', restore: null);

            return;
        }
        $this->text = '';
        $this->dispatch('chat-turn');
        $this->dispatch('chat-done', restore: null);
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
