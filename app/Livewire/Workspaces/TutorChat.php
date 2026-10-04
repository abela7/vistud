<?php

namespace App\Livewire\Workspaces;

use App\Engine\ChatMarks;
use App\Engine\Choices;
use App\Engine\EngineFailed;
use App\Engine\SessionChat;
use App\Engine\Settings;
use App\Identity\PrincipalFactory;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\MarkdownPreview;
use App\Study\Sessions;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The built-in chat on a study session's page (docs/specs/study-memory.md §6): the student's turns and the
 * tutor's, the look-ups each answer made and what the chat has cost against the limit, a box to write in, and
 * a way to keep what the tutor marked (the write-back's review, opened on the chat's replies). A thin adapter
 * over App\Engine\SessionChat; the engine's refusals show as a line under the box.
 */
final class TutorChat extends Component
{
    public const SUGGESTIONS = [
        'Where did we stop last time, and what should we do today?',
        'Explain this topic to me simply, one part at a time.',
        'Quiz me on what I should know by now.',
    ];

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

    public function boot(SessionChat $chat, Settings $settings, Sessions $sessions, PrincipalFactory $principals): void
    {
        $this->chat = $chat;
        $this->settings = $settings;
        $this->sessions = $sessions;
        $this->principals = $principals;
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

    public function send(): void
    {
        $this->error = null;
        $text = trim($this->text);
        if ($text === '') {
            return;
        }
        try {
            $this->chat->send($this->principal(), $this->sessionId, $text);
        } catch (Unprocessable|EngineFailed $e) {
            $this->error = $e->getMessage();

            return;
        }
        $this->text = '';
        $this->dispatch('chat-turn');
    }

    /** One of the suggested openings. */
    public function say(string $text): void
    {
        $this->text = in_array($text, self::SUGGESTIONS, true) ? $text : '';
        $this->send();
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
            }
        }
        unset($turn);

        return view('livewire.workspaces.tutor-chat', [
            'open' => $session->isOpen(),
            'ready' => $ready,
            'choices' => $choices,
            'turns' => $turns,
            'marked' => $marked,
            'spentWords' => Choices::dollars($spent['session']).($spent['session_cap'] > 0 ? ' of '.Choices::dollars($spent['session_cap']) : '').' this session',
            'suggestions' => self::SUGGESTIONS,
        ]);
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
