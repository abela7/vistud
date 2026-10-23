<?php

namespace App\Livewire\Study;

use App\Engine\VistudAi;
use App\Identity\PrincipalFactory;
use App\Platform\Access\Principal;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * ViStud AI from any student page (the owner's ask, 2026-10-23): a button in the corner opens a panel with the same
 * chat as the ViStud AI page, going on with the latest conversation, with New chat and the full page a click away.
 * The chat is loaded only once the panel is opened. Not shown where a chat already is, or where it would get in the way
 * (HIDDEN_ON, and a note opened in a window of its own).
 */
final class VistudAiDock extends Component
{
    /** The pages it stays off: those with a chat of their own, the full-screen ones, and the AI apps' consent. */
    public const HIDDEN_ON = [
        'vistud-ai', 'workspaces.sessions.show', 'workspaces.sessions.briefing', 'workspaces.topics.show',
        'workspaces.assignments.work', 'workspaces.guide', 'workspaces.notes.ask-ai', 'workspaces.flashcards.review',
        'connections.*',
    ];

    #[Locked]
    public bool $open = false;

    /** The conversation the panel shows; null for a new one. */
    #[Locked]
    public ?string $conversation = null;

    /** Changes for New chat, so the chat starts over. */
    #[Locked]
    public int $chat = 0;

    private VistudAi $ai;

    private PrincipalFactory $principals;

    public function boot(VistudAi $ai, PrincipalFactory $principals): void
    {
        [$this->ai, $this->principals] = [$ai, $principals];
    }

    /** Whether this page shows the button. */
    public static function shows(): bool
    {
        return ! Route::is(...self::HIDDEN_ON) && ! request()->boolean('window');
    }

    /** Opens the panel on the latest conversation (a new one when there is none). */
    public function show(): void
    {
        if (! $this->open && $this->conversation === null) {
            $this->conversation = $this->ai->conversations($this->principal())[0]['id'] ?? null;
            $this->chat++;
        }
        $this->open = true;
    }

    public function hide(): void
    {
        $this->open = false;
    }

    public function newChat(): void
    {
        [$this->conversation, $this->chat] = [null, $this->chat + 1];
    }

    /** The chat started a conversation with its first words: it is the one the panel goes on with. */
    #[On('vistud-conversation-started')]
    public function started(string $id): void
    {
        $this->conversation = $id;
    }

    public function render(): View
    {
        return view('livewire.study.vistud-ai-dock');
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
