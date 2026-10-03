<?php

namespace App\Livewire\Workspaces;

use App\Identity\PrincipalFactory;
use App\Livewire\Concerns\EditsPlan;
use App\Livewire\Concerns\Notices;
use App\Platform\Access\Principal;
use App\Study\Activities;
use App\Study\Files;
use App\Study\Notes;
use App\Study\PlanItem;
use App\Study\PlanMaker;
use App\Study\Plans;
use App\Study\PlanStarters;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * An assignment's plan, on its page (the owner's review, 2026-10-02): the one way to track any assignment, from an
 * essay to a group project. Parts (its sections or deliverables) with steps under them (which can hold steps), steps
 * on their own, milestones, the marking criteria to check oneself against and the team that shares the work, with
 * the progress, how it is going and what is left against the time left. Made in a moment from a starter or an AI's
 * reply, or by hand. A section is made, and worked on, on its own page (SectionForm, SectionPage); here each is one
 * line (or card) of how far it has got. The assignment's ID is locked; the service checks everything.
 */
final class AssignmentPlan extends Component
{
    use EditsPlan, Notices;

    #[Locked]
    public string $workspaceId;

    #[Locked]
    public string $activityId;

    public string $criterionText = '';

    public string $criterionMarks = '';

    public string $milestoneText = '';

    public string $milestoneDate = '';

    public bool $showMilestones = false;

    public bool $showCriteria = false;

    /** Asking whether to remove everything in the plan. */
    public bool $confirmClear = false;

    public bool $showTeam = false;

    public string $memberName = '';

    public bool $memberMe = false;

    /** The person being renamed, or null. */
    #[Locked]
    public ?string $renamingMember = null;

    public string $memberRename = '';

    public bool $showStarters = false;

    public bool $aiOpen = false;

    public string $brief = '';

    public string $reply = '';

    /** The reply has been read: its parts, steps and criteria are shown, and can be added. */
    public bool $reading = false;

    private Plans $plans;

    private PlanMaker $maker;

    private Activities $activities;

    private Files $files;

    private Notes $notes;

    private PrincipalFactory $principals;

    public function boot(Plans $plans, PlanMaker $maker, Activities $activities, Files $files, Notes $notes, PrincipalFactory $principals): void
    {
        [$this->plans, $this->maker, $this->activities, $this->files, $this->notes, $this->principals] = [$plans, $maker, $activities, $files, $notes, $principals];
    }

    public function mount(string $workspaceId, string $activityId): void
    {
        [$this->workspaceId, $this->activityId] = [$workspaceId, $activityId];
    }

    // ---------- Adding ----------

    public function addMilestone(): void
    {
        if ($this->attempt(['title' => 'milestoneText', 'due_on' => 'milestoneDate'], fn () => $this->plans->addMilestone($this->principal(), $this->activityId, $this->milestoneText, $this->milestoneDate))) {
            $this->reset('milestoneText', 'milestoneDate');
            $this->changed();
        }
    }

    public function addCriterion(): void
    {
        if ($this->attempt(['title' => 'criterionText', 'marks' => 'criterionMarks'], fn () => $this->plans->addCriterion($this->principal(), $this->activityId, $this->criterionText, $this->criterionMarks))) {
            $this->reset('criterionText', 'criterionMarks');
            $this->changed();
        }
    }

    public function useStarter(string $key): void
    {
        if ($this->attempt([], fn () => $this->plans->applyStarter($this->principal(), $this->activityId, $key))) {
            $this->showStarters = false;
            $this->notify('Added the “'.(PlanStarters::get($key)['label'] ?? 'starter').'” plan. Change anything that doesn\'t fit.');
            $this->changed();
        }
    }

    // ---------- With an AI ----------

    public function toggleAi(): void
    {
        $this->aiOpen = ! $this->aiOpen;
        $this->showStarters = false;
        $this->confirmClear = false;
        $this->reading = false;
    }

    /** The pre-made plans, shown only when asked for. */
    public function toggleStarters(): void
    {
        $this->showStarters = ! $this->showStarters;
        $this->aiOpen = false;
        $this->confirmClear = false;
    }

    public function askClear(): void
    {
        $this->confirmClear = true;
        $this->showStarters = false;
        $this->aiOpen = false;
    }

    public function cancelClear(): void
    {
        $this->confirmClear = false;
    }

    /** Takes everything out of the plan (not the team), for a pre-made plan or a reply that was not wanted. */
    public function clearPlan(): void
    {
        $this->confirmClear = false;
        $this->cancelEdit();
        $this->adding = null;
        $removed = 0;
        $folders = collect($this->plans->get($this->principal(), $this->activityId)->parts())->contains(fn (PlanItem $part) => $part->folderId !== null);
        if ($this->attempt([], function () use (&$removed) {
            $removed = $this->plans->clear($this->principal(), $this->activityId);
        })) {
            $this->notify(match (true) {
                $removed === 0 => 'The plan was already empty.',
                $folders => 'The plan is cleared. What you added to its sections stays in the assignment\'s folder.',
                default => 'The plan is cleared.',
            });
            $this->changed();
        }
    }

    public function updatedReply(): void
    {
        $this->reading = false;
    }

    public function readReply(): void
    {
        $this->resetErrorBag('reply');
        $read = PlanMaker::read($this->reply);
        if ($read['parts'] === [] && $read['steps'] === [] && $read['criteria'] === [] && $read['milestones'] === []) {
            $this->reading = false;
            $this->addError('reply', 'No parts, steps, milestones or criteria found in it. Paste the AI\'s whole reply, with the marks it wrote.');

            return;
        }
        $this->reading = true;
    }

    public function addRead(): void
    {
        $added = null;
        if ($this->attempt([], function () use (&$added) {
            $added = $this->plans->addAll($this->principal(), $this->activityId, PlanMaker::read($this->reply));
        })) {
            $this->reset('brief', 'reply', 'reading', 'aiOpen');
            $this->notify($added === 1 ? '1 thing added to your plan.' : "{$added} things added to your plan.");
            $this->changed();
        }
    }

    // ---------- Ticking, editing, ordering ----------

    public function addMember(): void
    {
        if ($this->attempt(['name' => 'memberName'], fn () => $this->plans->addMember($this->principal(), $this->activityId, $this->memberName, $this->memberMe))) {
            $this->reset('memberName', 'memberMe');
            $this->changed();
        }
    }

    public function toggleMe(string $memberId, bool $me): void
    {
        if ($this->attempt([], fn () => $this->plans->markMe($this->principal(), $memberId, $me))) {
            $this->changed();
        }
    }

    public function startRename(string $memberId): void
    {
        $member = $this->plans->get($this->principal(), $this->activityId)->member($memberId);
        if ($member !== null) {
            [$this->renamingMember, $this->memberRename] = [$member->id, $member->name];
            $this->resetErrorBag();
        }
    }

    public function saveRename(): void
    {
        if ($this->renamingMember !== null && $this->attempt(['name' => 'memberRename'], fn () => $this->plans->renameMember($this->principal(), $this->renamingMember, $this->memberRename))) {
            $this->cancelRename();
            $this->changed();
        }
    }

    public function cancelRename(): void
    {
        $this->reset('renamingMember', 'memberRename');
        $this->resetErrorBag();
    }

    /** Takes a person out of the team: what was theirs goes back to nobody. */
    public function removeMember(string $memberId): void
    {
        if ($this->attempt([], fn () => $this->plans->removeMember($this->principal(), $memberId))) {
            $this->changed();
        }
    }

    /** Every part and step is ticked: the assignment is done. */
    public function markDone(): void
    {
        $this->activities->setStatus($this->principal(), $this->activityId, 'done');
        $this->notify('Done. Well done!');
        $this->changed();
    }

    public function render(): View
    {
        $by = $this->principal();
        $plan = $this->plans->get($by, $this->activityId);
        $assignment = $this->activities->find($by, $this->activityId);
        $progress = $plan->progress();
        $read = $this->reading ? PlanMaker::read($this->reply) : null;
        $starters = PlanStarters::ALL;
        if ($assignment->kind === 'project') {
            // A project's own starters first.
            $starters = array_intersect_key($starters, ['project' => 1, 'group' => 1]) + $starters;
        }

        // How much each section keeps in its folder (shown on its line; the things themselves are on its page).
        $counts = [];
        $sectionFolders = array_flip(array_filter(array_map(fn (PlanItem $part) => $part->folderId, $plan->parts())));
        if ($sectionFolders !== []) {
            foreach (['files' => $this->files->list($by, $this->workspaceId), 'notes' => $this->notes->list($by, $this->workspaceId)] as $kind => $things) {
                foreach ($things as $thing) {
                    if ($thing->folderId !== null && isset($sectionFolders[$thing->folderId])) {
                        $counts[$thing->folderId][$kind] = ($counts[$thing->folderId][$kind] ?? 0) + 1;
                    }
                }
            }
        }

        return view('livewire.workspaces.assignment-plan', [
            'counts' => $counts,
            'plan' => $plan,
            'assignment' => $assignment,
            'progress' => $progress,
            'pace' => $progress->pace($assignment),
            'criteria' => $plan->criteriaScore(),
            'starters' => $starters,
            'health' => $plan->health($assignment),
            'workload' => $plan->workload(),
            'today' => now($assignment->zone)->toDateString(),
            'prompt' => $this->aiOpen ? $this->maker->prompt($by, $this->activityId, $this->brief) : '',
            'read' => $read,
        ]);
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
