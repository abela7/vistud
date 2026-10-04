<?php

namespace App\Livewire\Workspaces;

use App\Engine\Helper;
use App\Identity\PrincipalFactory;
use App\Livewire\Concerns\Notices;
use App\Platform\Access\Principal;
use App\Platform\Errors\AppError;
use App\Platform\Errors\Conflict;
use App\Study\Sessions;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Ask (docs/specs/vistud-2-blueprint.md §3.6.1, Phase 5): a short chat with the quick helper about the course, from a button in
 * the top bar of every course page. It can look things up in the course (topics, questions, key points, notes, files) and
 * changes nothing. The talk lives on this page only: it is not stored, and leaving the page ends it. Teaching and long
 * explanations belong to the tutor, so one line at the bottom starts a session. The course's id is locked.
 */
final class Ask extends Component
{
    use Notices;

    /** The most the student can ask at once, and how much of the talk the helper is reminded of. */
    public const MAX_ASK = 500;

    public const REMEMBER = 3_000;

    /** The talk kept on the page: the oldest go first. */
    public const KEEP = 12;

    #[Locked]
    public string $workspaceId;

    /** @var list<array{from: string, text: string}> from is you, helper or problem */
    #[Locked]
    public array $talk = [];

    public string $text = '';

    private Helper $helper;

    private Sessions $sessions;

    private PrincipalFactory $principals;

    public function boot(Helper $helper, Sessions $sessions, PrincipalFactory $principals): void
    {
        $this->helper = $helper;
        $this->sessions = $sessions;
        $this->principals = $principals;
    }

    public function mount(string $workspaceId): void
    {
        $this->workspaceId = $workspaceId;
    }

    /** Puts the student's question to the helper and adds its answer to the talk. */
    public function send(): void
    {
        $this->resetErrorBag();
        $words = trim($this->text);
        if ($words === '') {
            $this->addError('text', 'Write what you want to know.');

            return;
        }
        if (mb_strlen($words) > self::MAX_ASK) {
            $this->addError('text', 'Keep it to '.self::MAX_ASK.' characters.');

            return;
        }

        $earlier = $this->earlier();
        $this->talk[] = ['from' => 'you', 'text' => $words];
        $this->text = '';
        try {
            $answer = $this->helper->quick($this->principal(), $words, $earlier, null, $this->workspaceId);
            $this->talk[] = ['from' => 'helper', 'text' => $answer];
        } catch (AppError $e) {
            $this->talk[] = ['from' => 'problem', 'text' => $e->getMessage()];
        }
        $this->talk = array_slice($this->talk, -self::KEEP);
    }

    /** Starts over: nothing was kept anyway. */
    public function clear(): void
    {
        $this->talk = [];
        $this->text = '';
        $this->resetErrorBag();
    }

    /** The tutor teaches: back to the open session, or a new one in this course. */
    public function teach(): void
    {
        $by = $this->principal();
        $open = $this->sessions->current($by);
        if ($open !== null && $open->workspaceId === $this->workspaceId) {
            $this->redirectRoute('workspaces.sessions.show', [$this->workspaceId, $open->id], navigate: true);

            return;
        }
        try {
            $last = $this->sessions->lastChoices($by, $this->workspaceId);
            $session = $this->sessions->start($by, $this->workspaceId, null, null, $last['pomodoro'], $last['tutoring']);
        } catch (Conflict) {
            $this->notify('Another session is open. End it first, or go back to it.', 'warning');

            return;
        }
        $this->dispatch('session-changed');
        $this->redirectRoute('workspaces.sessions.show', [$this->workspaceId, $session->id], navigate: true);
    }

    public function render(): View
    {
        return view('livewire.workspaces.ask');
    }

    /** What was said so far, as a reminder for the helper: the last exchanges, within a few thousand characters. */
    private function earlier(): string
    {
        $lines = [];
        foreach ($this->talk as $line) {
            if ($line['from'] !== 'problem') {
                $lines[] = ($line['from'] === 'you' ? 'Student: ' : 'You: ').$line['text'];
            }
        }
        $text = implode("\n", array_slice($lines, -6));

        return $text === '' ? '' : 'The talk so far:'."\n".mb_substr($text, -self::REMEMBER);
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
