<?php

namespace App\Livewire\Workspaces;

use App\Engine\SessionChat;
use App\Identity\PrincipalFactory;
use App\Livewire\Concerns\Notices;
use App\Livewire\Concerns\PomodoroForm;
use App\Livewire\Concerns\TeachingForm;
use App\Platform\Access\Principal;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\Flashcards;
use App\Study\Modules;
use App\Study\SessionDetails;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Starting a study session and logging time studied without the clock
 * (docs/specs/study-memory.md §4), from anywhere in a workspace: the
 * `study-start` event (optionally with a module or topic), `study-next` (the same with no dialog) and `study-log`.
 * One session at a time: with one open, starting says so and offers to go
 * back to it or end it first; the two never mix.
 * With $stats, as on the Overview, it also shows the student's rhythm: the
 * streak, this week day by day, and the flashcards due. The service checks
 * everything.
 */
final class StudyTime extends Component
{
    use Notices, PomodoroForm, TeachingForm;

    #[Locked]
    public string $workspaceId;

    /** start, busy (another session is open) or log: the dialog that's open, or null. */
    #[Locked]
    public ?string $mode = null;

    /** The open session that has to end before a new one starts. */
    #[Locked]
    public ?string $busyId = null;

    /** The Overview's tiles: the streak, this week, the cards due. */
    #[Locked]
    public bool $stats = false;

    public string $topicId = '';

    public string $moduleId = '';

    public string $date = '';

    public string $time = '';

    public string $minutes = '';

    private Sessions $sessions;

    private Topics $topics;

    private Modules $modules;

    private Flashcards $flashcards;

    private Workspaces $workspaces;

    private PrincipalFactory $principals;

    private SessionChat $chat;

    public function boot(Sessions $sessions, Topics $topics, Modules $modules, Flashcards $flashcards, Workspaces $workspaces, PrincipalFactory $principals, SessionChat $chat): void
    {
        $this->chat = $chat;
        $this->workspaces = $workspaces;
        $this->flashcards = $flashcards;
        $this->sessions = $sessions;
        $this->topics = $topics;
        $this->modules = $modules;
        $this->principals = $principals;
    }

    public function mount(string $workspaceId, bool $stats = false): void
    {
        [$this->workspaceId, $this->stats] = [$workspaceId, $stats];
    }

    /** What to study: from the Overview, or a module's page (in that module). */
    #[On('study-start')]
    public function newSession(?string $moduleId = null, ?string $topicId = null): void
    {
        $this->open('start');
        [$this->moduleId, $this->topicId] = [$moduleId ?? '', $topicId ?? ''];
        $open = $this->sessions->current($this->principal());
        if ($open !== null) {
            [$this->mode, $this->busyId] = ['busy', $open->id];

            return;
        }
        // The clock and teaching the student chose last, ready again.
        $last = $this->sessions->lastChoices($this->principal(), $this->workspaceId);
        $this->fillPomodoro($last['pomodoro']);
        $this->fillTeaching($last['tutoring']);
    }

    /**
     * Study now, with no dialog (the course home's Study and the Next line): in the module and on the topic given,
     * the clock and teaching the student chose last (or, the first time, their answers to "How you learn"). With
     * another session open, the usual panel says so; if the session can't be started, the dialog opens.
     */
    #[On('study-next')]
    public function studyNext(?string $moduleId = null, ?string $topicId = null, ?string $ask = null): void
    {
        $by = $this->principal();
        if ($this->sessions->current($by) !== null) {
            $this->newSession($moduleId, $topicId);

            return;
        }
        $last = $this->sessions->lastChoices($by, $this->workspaceId);
        try {
            $session = $this->sessions->start($by, $this->workspaceId, $topicId ?: null, $moduleId ?: null, $last['pomodoro'], $last['tutoring'], in_array($ask, ['quiz', 'test'], true) ? $ask : null);
        } catch (Unprocessable|Conflict|NotFound) {
            $this->newSession($moduleId, $topicId);

            return;
        }
        $this->dispatch('session-changed');
        // `ask` (quiz or test) is the session's mode, and puts the ask in its message box, for the student to send.
        $this->redirectRoute('workspaces.sessions.show', [$this->workspaceId, $session->id] + (in_array($ask, ['quiz', 'test'], true) ? ['ask' => $ask] : []), navigate: true);
    }

    /** Ends the open session, then goes on to start the new one. */
    public function endOpen(): void
    {
        if ($this->mode !== 'busy' || $this->busyId === null) {
            return;
        }
        try {
            $ended = $this->sessions->end($this->principal(), $this->busyId);
            // Its chat's summary and checkpoint into its record, for the next session to start from.
            $wrapped = $this->chat->wrapUp($this->principal(), $this->busyId) !== null;
            $this->notice = 'Session ended. You studied '.SessionDetails::duration($ended->studySeconds).'.'.($wrapped ? ' The chat\'s summary is saved with it.' : '');
        } catch (Conflict|NotFound) {
            // It ended meanwhile.
        }
        $this->dispatch('session-changed');
        $this->newSession($this->moduleId ?: null, $this->topicId ?: null);
    }

    #[On('study-log')]
    public function logTime(): void
    {
        $this->open('log');
        $now = CarbonImmutable::now($this->zone());
        [$this->date, $this->time] = [$now->format('Y-m-d'), $now->subHour()->format('H:00')];
    }

    public function save(): void
    {
        if ($this->mode === 'busy') {
            return;
        }
        $by = $this->principal();
        $this->resetErrorBag();

        try {
            if ($this->mode === 'start') {
                $session = $this->sessions->start($by, $this->workspaceId, $this->topicId ?: null, $this->moduleId ?: null, $this->pomodoroInput(), $this->teachingInput());
                $this->dispatch('session-changed');
                $this->redirectRoute('workspaces.sessions.show', [$this->workspaceId, $session->id], navigate: true);

                return;
            }
            if ($this->mode === 'log') {
                $logged = $this->sessions->log($by, $this->workspaceId, ['date' => $this->date, 'time' => $this->time, 'minutes' => $this->minutes, 'topic_id' => $this->topicId], $this->zone());
                $this->notice = SessionDetails::duration($logged->studySeconds).' logged.';
                $this->dispatch('session-changed');
            }
        } catch (Unprocessable $e) {
            foreach ($e->details['fields'] ?? [] as $field => $messages) {
                $this->addError($this->teachingErrorField($this->pomodoroErrorField($field)), $messages[0]);
            }

            return;
        } catch (Conflict $e) {
            if ($e->errorCode === 'session_open') {
                // Started elsewhere meanwhile, in another tab.
                $this->newSession($this->moduleId ?: null, $this->topicId ?: null);

                return;
            }
            $this->addError('topicId', $e->getMessage());

            return;
        } catch (NotFound) {
            $this->addError('topicId', 'That no longer exists. Close this and try again.');

            return;
        }

        $this->close();
        $this->dispatch('study-dialog-close');
    }

    public function close(): void
    {
        $this->reset('mode', 'busyId', 'topicId', 'moduleId', 'date', 'time', 'minutes', 'clock', 'preset', 'focus', 'short', 'long', 'every', 'auto', 'method', 'checkIns', 'quiz', 'pace');
        $this->resetErrorBag();
    }

    #[On('session-changed')]
    public function refresh(): void {}

    public function render(): View
    {
        $by = $this->principal();
        $data = [
            'topics' => $this->mode === null ? [] : $this->topics->list($by, $this->workspaceId),
            'modules' => $this->mode === 'start' ? $this->modules->list($by, $this->workspaceId) : [],
            'busy' => $this->mode === 'busy' ? $this->busy($by) : null,
        ];
        if ($this->stats) {
            $zone = $this->zone();
            $data += [
                'rhythm' => $this->sessions->rhythm($by, $this->workspaceId),
                'cards' => $this->flashcards->counts($by, $this->workspaceId),
                'today' => CarbonImmutable::now($zone)->toDateString(),
            ];
        }

        return view('livewire.workspaces.study-time', $data);
    }

    /** @return ?array{session: SessionDetails, title: string, where: ?string} the open session, as the busy panel shows it */
    private function busy(Principal $by): ?array
    {
        try {
            $session = $this->sessions->find($by, (string) $this->busyId);
        } catch (NotFound) {
            return null;
        }
        $title = null;
        try {
            $title = $session->topicId !== null ? $this->topics->find($by, $session->topicId)->name : null;
            $title ??= $session->moduleId !== null ? $this->modules->find($by, $session->moduleId)->title : null;
        } catch (NotFound) {
            // Removed since.
        }
        $where = $session->workspaceId === $this->workspaceId ? null : $this->workspaces->find($by, $session->workspaceId)->name;

        return ['session' => $session, 'title' => $title ?? 'Study session', 'where' => $where];
    }

    private function open(string $mode): void
    {
        $this->close();
        $this->mode = $mode;
        $this->dispatch('study-dialog-open');
    }

    private function zone(): string
    {
        return $this->sessions->timezone($this->principal());
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
