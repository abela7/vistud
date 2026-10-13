<?php

namespace App\Livewire\Study;

use App\Engine\VistudAi;
use App\Identity\PrincipalFactory;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * ViStud AI's page on the main dashboard (App\Engine\VistudAi): the student's conversations, latest first, and the one
 * open, in the same chat as a session's (App\Livewire\Workspaces\TutorChat in its ViStud AI mode). A new conversation
 * starts with its first words; one can be opened again, or deleted. Never inside a course.
 */
final class VistudAiPage extends Component
{
    /** The conversation open, in the address (?c=), or null for a new one. */
    #[Url(as: 'c', except: null)]
    public ?string $conversation = null;

    /** Words to find a conversation by its title. */
    public string $search = '';

    /** Changes when another conversation is opened, so the chat starts on it. */
    #[Locked]
    public int $chat = 0;

    private VistudAi $ai;

    private PrincipalFactory $principals;

    public function boot(VistudAi $ai, PrincipalFactory $principals): void
    {
        $this->ai = $ai;
        $this->principals = $principals;
    }

    public function mount(): void
    {
        // An address to a conversation that is gone (or another student's) opens a new one.
        if ($this->conversation !== null) {
            try {
                $this->ai->model($this->principal(), $this->conversation);
            } catch (NotFound) {
                $this->conversation = null;
            }
        }
    }

    public function newChat(): void
    {
        [$this->conversation, $this->chat] = [null, $this->chat + 1];
    }

    public function open(string $id): void
    {
        try {
            $this->ai->model($this->principal(), $id);
        } catch (NotFound) {
            return;
        }
        [$this->conversation, $this->chat] = [$id, $this->chat + 1];
    }

    public function delete(string $id): void
    {
        try {
            $this->ai->delete($this->principal(), $id);
        } catch (NotFound) {
            return;
        }
        if ($this->conversation === $id) {
            $this->newChat();
        }
    }

    /** The chat started a conversation with its first words: it is listed, and the address names it. */
    #[On('vistud-conversation-started')]
    public function started(string $id): void
    {
        $this->conversation = $id;
    }

    /** An answer came: the list is put in order again. */
    #[On('chat-turn')]
    public function answered(): void {}

    public function render(): View
    {
        $by = $this->principal();
        $conversations = $this->ai->conversations($by, mb_substr($this->search, 0, 80));
        $current = null;
        foreach ($conversations as $item) {
            $current = $item['id'] === $this->conversation ? $item['title'] : $current;
        }

        return view('livewire.study.vistud-ai-page', [
            'conversations' => $conversations,
            'title' => $current ?? ($this->conversation !== null ? 'A conversation' : 'New chat'),
        ]);
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
