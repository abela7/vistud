<?php

namespace App\Livewire\Workspaces;

use App\Identity\PrincipalFactory;
use App\Livewire\Concerns\PomodoroForm;
use App\Platform\Access\Principal;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
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
 * Study time on a workspace's Overview (docs/specs/study-memory.md §4):
 * this week and in all, the latest sessions, starting one, and logging time
 * studied without the clock. The service checks everything.
 */
final class StudyTime extends Component
{
    use PomodoroForm;

    #[Locked]
    public string $workspaceId;

    /** start or log: the dialog that's open, or null. */
    #[Locked]
    public ?string $mode = null;

    #[Locked]
    public ?string $notice = null;

    public string $topicId = '';

    public string $moduleId = '';

    public string $date = '';

    public string $time = '';

    public string $minutes = '';

    private Sessions $sessions;

    private Topics $topics;

    private Modules $modules;

    private Workspaces $workspaces;

    private PrincipalFactory $principals;

    public function boot(Sessions $sessions, Topics $topics, Modules $modules, Workspaces $workspaces, PrincipalFactory $principals): void
    {
        $this->sessions = $sessions;
        $this->topics = $topics;
        $this->modules = $modules;
        $this->workspaces = $workspaces;
        $this->principals = $principals;
    }

    public function mount(string $workspaceId): void
    {
        $this->workspaceId = $workspaceId;
    }

    /** From the Overview's header, or the card: what to study. */
    #[On('study-start')]
    public function newSession(): void
    {
        $this->open('start');
        // The clock the student used last, ready again.
        $last = collect($this->sessions->list($this->principal(), $this->workspaceId, 10))->first(fn ($s) => ! $s->manual);
        $this->fillPomodoro($last?->pomodoro);
    }

    public function logTime(): void
    {
        $this->open('log');
        $now = CarbonImmutable::now($this->zone());
        [$this->date, $this->time] = [$now->format('Y-m-d'), $now->subHour()->format('H:00')];
    }

    public function save(): void
    {
        $by = $this->principal();
        $this->resetErrorBag();

        try {
            if ($this->mode === 'start') {
                $session = $this->sessions->start($by, $this->workspaceId, $this->topicId ?: null, $this->moduleId ?: null, $this->pomodoroInput());
                $this->dispatch('session-changed');
                $this->redirectRoute('workspaces.sessions.show', [$this->workspaceId, $session->id]);

                return;
            }
            if ($this->mode === 'log') {
                $logged = $this->sessions->log($by, $this->workspaceId, ['date' => $this->date, 'time' => $this->time, 'minutes' => $this->minutes, 'topic_id' => $this->topicId], $this->zone());
                $this->notice = SessionDetails::duration($logged->studySeconds).' logged.';
            }
        } catch (Unprocessable $e) {
            foreach ($e->details['fields'] ?? [] as $field => $messages) {
                $this->addError($this->pomodoroErrorField($field), $messages[0]);
            }

            return;
        } catch (Conflict $e) {
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
        $this->reset('mode', 'topicId', 'moduleId', 'date', 'time', 'minutes', 'clock', 'preset', 'focus', 'short', 'long', 'every', 'auto');
        $this->resetErrorBag();
    }

    #[On('session-changed')]
    public function refresh(): void {}

    public function render(): View
    {
        $by = $this->principal();
        $zone = $this->zone();
        $weekStart = CarbonImmutable::now($zone)->startOfWeek()->utc();
        $open = $this->sessions->current($by);
        $topics = $this->topics->list($by, $this->workspaceId);
        $topicNames = [];
        foreach ($topics as $topic) {
            $topicNames[$topic->id] = $topic->name;
        }

        return view('livewire.workspaces.study-time', [
            'totals' => $this->sessions->totals($by, $this->workspaceId, $weekStart),
            'recent' => array_slice(array_values(array_filter($this->sessions->list($by, $this->workspaceId, 6), fn ($s) => ! $s->isOpen())), 0, 4),
            'open' => $open,
            'openWorkspace' => $open !== null && $open->workspaceId !== $this->workspaceId ? $this->workspaces->find($by, $open->workspaceId) : null,
            'topics' => $topics,
            'topicNames' => $topicNames,
            'modules' => $this->mode === 'start' ? $this->modules->list($by, $this->workspaceId) : [],
            'zone' => $zone,
        ]);
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
