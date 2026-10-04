<?php

namespace App\Livewire\Workspaces;

use App\Engine\ChatMarks;
use App\Engine\Choices;
use App\Engine\EngineFailed;
use App\Engine\SessionChat;
use App\Engine\Settings;
use App\Engine\Toolbox;
use App\Identity\PrincipalFactory;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\MarkdownPreview;
use App\Study\Sessions;
use Closure;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The built-in chat on a study session's page (docs/specs/study-memory.md §6): the student's turns and the
 * tutor's, the look-ups each answer made and what the chat has cost against the limit, a box to write in, and
 * a way to keep what the tutor marked (the write-back's review, opened on the chat's replies). The answer
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

    public function boot(SessionChat $chat, Settings $settings, Sessions $sessions, PrincipalFactory $principals, ChatStream $stream): void
    {
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

    /** Sends the student's words (given by the page, or in $text) and streams the answer. */
    public function send(?string $text = null): void
    {
        $words = trim($text ?? $this->text);
        if ($words === '') {
            $this->dispatch('chat-done', restore: null);

            return;
        }
        $this->turn(fn (Closure $on) => $this->chat->send($this->principal(), $this->sessionId, $words, $on), $words);
    }

    /** One of the suggested openings. */
    public function say(string $text): void
    {
        if (! in_array($text, self::SUGGESTIONS, true)) {
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
        ]);
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
            $this->error = $e->getMessage();
            $this->dispatch('chat-done', restore: $words);

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
