<?php

namespace App\Livewire\Workspaces;

use App\Engine\Jobs\Runner;
use App\Engine\Jobs\UnderstandAssignment;
use App\Engine\SessionChat;
use App\Identity\PrincipalFactory;
use App\Livewire\Concerns\Notices;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Study\Activities;
use App\Study\AssignmentLog;
use App\Study\AssignmentSources;
use App\Study\AssignmentUnderstandings;
use App\Study\Modules;
use App\Study\Plans;
use App\Study\Workspaces;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The assignment companion screen (docs/specs/vistud-2-blueprint.md):
 * working on an assignment with the tutor across understand -> plan -> do -> review -> submit.
 * Left/main: tutor chat in assignment mode.
 * Right rail: stage stepper, understanding picture summary, plan progress, and activity log.
 */
final class AssignmentCompanion extends Component
{
    use Notices;

    #[Locked]
    public string $workspaceId;

    #[Locked]
    public string $activityId;

    public string $newLogStage = '';

    public string $newLogKind = 'progress';

    public string $newLogText = '';

    /** The reading job being waited for. */
    #[Locked]
    public ?string $jobId = null;

    /** Why the last reading failed (if any). */
    #[Locked]
    public ?string $problem = null;

    #[Locked]
    public bool $readAgain = false;

    private Activities $activities;

    private Workspaces $workspaces;

    private AssignmentUnderstandings $understandings;

    private AssignmentSources $sources;

    private Plans $plans;

    private AssignmentLog $log;

    private SessionChat $chat;

    private Runner $runner;

    private PrincipalFactory $principals;

    public function boot(
        Activities $activities,
        Workspaces $workspaces,
        AssignmentUnderstandings $understandings,
        AssignmentSources $sources,
        Plans $plans,
        AssignmentLog $log,
        SessionChat $chat,
        Runner $runner,
        PrincipalFactory $principals,
    ): void {
        $this->activities = $activities;
        $this->workspaces = $workspaces;
        $this->understandings = $understandings;
        $this->sources = $sources;
        $this->plans = $plans;
        $this->log = $log;
        $this->chat = $chat;
        $this->runner = $runner;
        $this->principals = $principals;
    }

    public function mount(string $workspaceId, string $activityId): void
    {
        $this->workspaceId = $workspaceId;
        $this->activityId = $activityId;
        $activity = $this->activities->find($this->principal(), $activityId);
        $activity->workspaceId === $workspaceId || throw new NotFound;
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }

    public function setStage(string $stage): void
    {
        if (! in_array($stage, Activities::STAGES, true)) {
            return;
        }

        $this->activities->setStage($this->principal(), $this->activityId, $stage);
        $this->notify("Moved to stage: {$stage}");
        $this->dispatch('assignment-changed');
    }

    public function addLog(): void
    {
        $text = trim($this->newLogText);
        if ($text === '') {
            return;
        }

        if (mb_strlen($text) > 500) {
            $this->addError('newLogText', 'Keep log entries to 500 characters.');

            return;
        }

        $by = $this->principal();
        $activity = $this->activities->find($by, $this->activityId);
        $stage = $this->newLogStage !== '' && in_array($this->newLogStage, Activities::STAGES, true)
            ? $this->newLogStage
            : $activity->stage;

        $kind = in_array($this->newLogKind, AssignmentLog::KINDS, true)
            ? $this->newLogKind
            : 'progress';

        $this->log->add($by, $this->activityId, $stage, $kind, $text, byRole: 'student');
        $this->reset('newLogText');
        $this->notify('Added to log.');
        $this->dispatch('assignment-changed');
    }

    public function deleteLog(string $id): void
    {
        try {
            $this->log->delete($this->principal(), $id);
            $this->notify('Log entry removed.');
            $this->dispatch('assignment-changed');
        } catch (NotFound) {
            // Already deleted
        }
    }

    /** Triggers reading/updating the understanding of the assignment. */
    public function understand(): void
    {
        $by = $this->principal();
        if ($this->sources->list($by, $this->activityId) === [] && $this->sources->files($by, $this->activityId) === []) {
            $this->problem = 'no_sources';

            return;
        }
        $this->problem = null;
        $this->jobId = $this->runner->pending($by, 'understand_assignment', 'activity', $this->activityId)
            ?? $this->runner->start($by, new UnderstandAssignment($this->workspaceId, $this->activityId));
        $this->check();
    }

    /** Polls the reading status. */
    public function check(): void
    {
        if ($this->jobId === null) {
            return;
        }
        try {
            $status = $this->runner->status($this->principal(), $this->jobId);
        } catch (NotFound) {
            $this->jobId = null;

            return;
        }
        if (in_array($status['status'], ['queued', 'running'], true)) {
            return;
        }
        $this->jobId = null;
        if ($status['status'] !== 'done') {
            $this->problem = $status['error_code'] ?? 'error';

            return;
        }
        // Stale check
        $latest = $this->understandings->latest($this->principal(), $this->activityId);
        if (! $this->readAgain && $latest !== null && $this->sources->outOfDate($this->principal(), $this->activityId, $latest)) {
            $this->readAgain = true;
            $this->understand();

            return;
        }
        $this->readAgain = false;
        $this->dispatch('assignment-changed');
    }

    /** Clears the assignment companion chat. */
    public function startOver(): void
    {
        $this->chat->clearAssignmentChat($this->principal(), $this->activityId);
        $this->dispatch('chat-reload');
        $this->dispatch('assignment-changed');
        $this->notify('Chat cleared.');
    }

    #[On('assignment-changed')]
    public function assignmentChanged(): void
    {
        // Re-renders the component
    }

    public function render(): View
    {
        $by = $this->principal();
        $activity = $this->activities->find($by, $this->activityId);
        $workspace = $this->workspaces->find($by, $this->workspaceId);
        $latest = $this->understandings->latest($by, $this->activityId);
        $written = $this->sources->list($by, $this->activityId);
        $files = $this->sources->files($by, $this->activityId);
        $stale = $latest !== null && $this->sources->outOfDate($by, $this->activityId, $latest);
        $plan = $this->plans->get($by, $this->activityId);
        $progress = $plan->progress();
        $health = $plan->health($activity);
        $logs = $this->log->list($by, $this->activityId, limit: 50);
        $module = $activity->moduleId !== null ? app(Modules::class)->find($by, $activity->moduleId) : null;

        return view('livewire.workspaces.assignment-companion', [
            'activity' => $activity,
            'workspace' => $workspace,
            'moduleTitle' => $module?->title,
            'understanding' => $latest,
            'sourcesCount' => count($written) + count($files),
            'stale' => $stale,
            'plan' => $plan,
            'planProgress' => $progress->percent,
            'planHealth' => $health,
            'sections' => $plan->parts(),
            'criteria' => $plan->criteria(),
            'logs' => $logs,
            'stages' => Activities::STAGES,
            'reading' => $this->jobId !== null,
        ]);
    }
}
