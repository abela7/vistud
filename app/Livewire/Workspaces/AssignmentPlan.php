<?php

namespace App\Livewire\Workspaces;

use App\Identity\PrincipalFactory;
use App\Livewire\Concerns\Notices;
use App\Platform\Access\Principal;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\Activities;
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
 * reply, or by hand. The assignment's ID is locked; the service checks everything.
 */
final class AssignmentPlan extends Component
{
    use Notices;

    #[Locked]
    public string $workspaceId;

    #[Locked]
    public string $activityId;

    /** What is typed in each "Add a step" box, by part ID; `loose` is the box for steps outside any part. */
    public array $stepText = [];

    public string $partText = '';

    public string $partMarks = '';

    public string $criterionText = '';

    public string $criterionMarks = '';

    /** The step that is being given a step of its own, or null. */
    #[Locked]
    public ?string $adding = null;

    /** The item being changed (its name, marks, dates, priority, labels, notes, person), or null. */
    #[Locked]
    public ?string $editing = null;

    public string $editTitle = '';

    public string $editMarks = '';

    public string $editStart = '';

    public string $editDue = '';

    public string $editPriority = '';

    public string $editLabels = '';

    public string $editNotes = '';

    public string $editMember = '';

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

    private PrincipalFactory $principals;

    public function boot(Plans $plans, PlanMaker $maker, Activities $activities, PrincipalFactory $principals): void
    {
        [$this->plans, $this->maker, $this->activities, $this->principals] = [$plans, $maker, $activities, $principals];
    }

    public function mount(string $workspaceId, string $activityId): void
    {
        [$this->workspaceId, $this->activityId] = [$workspaceId, $activityId];
    }

    // ---------- Adding ----------

    /** A step under a part or a step, or outside every part for none. Its text box is stepText[that ID], or stepText.loose. */
    public function addStep(string $parentId = ''): void
    {
        $box = $parentId === '' ? 'loose' : $parentId;
        $text = $this->stepText[$box] ?? '';
        $added = $this->attempt(['title' => "stepText.{$box}"], fn () => $this->plans->addStep($this->principal(), $this->activityId, $text, $parentId === '' ? null : $parentId));
        if ($added) {
            unset($this->stepText[$box]);
            $this->changed();
        }
    }

    /** Opens (or closes) the box for a step of a step. */
    public function toggleSub(string $stepId): void
    {
        $this->adding = $this->adding === $stepId ? null : $stepId;
        $this->resetErrorBag();
    }

    public function addMilestone(): void
    {
        if ($this->attempt(['title' => 'milestoneText', 'due_on' => 'milestoneDate'], fn () => $this->plans->addMilestone($this->principal(), $this->activityId, $this->milestoneText, $this->milestoneDate))) {
            $this->reset('milestoneText', 'milestoneDate');
            $this->changed();
        }
    }

    public function addPart(): void
    {
        if ($this->attempt(['title' => 'partText', 'marks' => 'partMarks'], fn () => $this->plans->addPart($this->principal(), $this->activityId, $this->partText, $this->partMarks))) {
            $this->reset('partText', 'partMarks');
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
        if ($this->attempt([], function () use (&$removed) {
            $removed = $this->plans->clear($this->principal(), $this->activityId);
        })) {
            $this->notify($removed === 0 ? 'The plan was already empty.' : 'The plan is cleared.');
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

    /** todo, doing, stuck or done for a part or a step; not_yet, partly or met for a criterion; pending or achieved for a milestone. */
    public function setState(string $id, string $state): void
    {
        if ($this->attempt([], fn () => $this->plans->setState($this->principal(), $id, $state))) {
            $this->changed();
        }
    }

    public function startEdit(string $id): void
    {
        $item = $this->find($id);
        if ($item === null) {
            return;
        }
        [$this->editing, $this->editTitle, $this->editMarks] = [$item->id, $item->title, (string) $item->weight];
        [$this->editStart, $this->editDue, $this->editPriority] = [(string) $item->startOn, (string) $item->dueOn, (string) $item->priority];
        [$this->editLabels, $this->editNotes, $this->editMember] = [implode(', ', $item->labels), (string) $item->notes, (string) $item->memberId];
        $this->resetErrorBag();
    }

    public function saveEdit(): void
    {
        $item = $this->editing === null ? null : $this->find($this->editing);
        if ($item === null) {
            $this->cancelEdit();

            return;
        }
        $fields = ['title' => $this->editTitle];
        if (in_array($item->kind, ['part', 'criterion'], true)) {
            $fields['marks'] = $this->editMarks;
        }
        if (in_array($item->kind, ['part', 'step', 'milestone'], true)) {
            $fields += ['due_on' => $this->editDue, 'notes' => $this->editNotes];
        }
        if (in_array($item->kind, ['part', 'step'], true)) {
            $fields += ['start_on' => $this->editStart, 'priority' => $this->editPriority, 'labels' => $this->editLabels, 'member_id' => $this->editMember];
        }
        $map = ['title' => 'editTitle', 'marks' => 'editMarks', 'start_on' => 'editStart', 'due_on' => 'editDue', 'priority' => 'editPriority', 'labels' => 'editLabels', 'notes' => 'editNotes', 'member' => 'editMember'];
        if ($this->attempt($map, fn () => $this->plans->update($this->principal(), $item->id, $fields))) {
            $this->cancelEdit();
            $this->changed();
        }
    }

    public function cancelEdit(): void
    {
        $this->reset('editing', 'editTitle', 'editMarks', 'editStart', 'editDue', 'editPriority', 'editLabels', 'editNotes', 'editMember');
        $this->resetErrorBag();
    }

    public function move(string $id, string $direction): void
    {
        if ($this->attempt([], fn () => $this->plans->move($this->principal(), $id, $direction))) {
            $this->changed();
        }
    }

    public function remove(string $id): void
    {
        if ($this->attempt([], fn () => $this->plans->delete($this->principal(), $id))) {
            $this->changed();
        }
    }

    // ---------- The team ----------

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

        return view('livewire.workspaces.assignment-plan', [
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

    /** Runs a change; true when it was made. A refusal goes to the field it names ($fields maps the service's field to a property). */
    private function attempt(array $fields, callable $change): bool
    {
        // What was refused before is cleared as the same fields are sent again.
        foreach ($fields as $property) {
            $this->resetErrorBag($property);
        }
        try {
            $change();

            return true;
        } catch (Unprocessable $e) {
            foreach ($e->details['fields'] ?? [] as $field => $messages) {
                $this->addError($fields[$field] ?? 'plan', $messages[0]);
            }
        } catch (Conflict $e) {
            $this->notify($e->getMessage(), 'info');
        } catch (NotFound) {
            $this->notify('That is no longer in the plan.', 'info');
        }

        return false;
    }

    private function find(string $id): ?PlanItem
    {
        foreach ($this->plans->get($this->principal(), $this->activityId)->items as $item) {
            if ($item->id === $id) {
                return $item;
            }
        }

        return null;
    }

    /** The page's status and countdown follow what was ticked. */
    private function changed(): void
    {
        $this->dispatch('plan-changed');
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
