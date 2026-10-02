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
 * An assignment's plan, on its page (the owner's review, 2026-10-02): the one way to track any assignment. Parts
 * (its sections or deliverables) with steps under them, steps on their own, and the marking criteria to check
 * oneself against, with the progress, and what is left against the time left. Made in a moment from a starter or
 * an AI's reply, or by hand. The assignment's ID is locked; the service checks everything.
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

    /** The item being renamed or given marks, or null. */
    #[Locked]
    public ?string $editing = null;

    public string $editTitle = '';

    public string $editMarks = '';

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

    /** A step under a part, or outside every part for no part. Its text box is stepText[the part's ID], or stepText.loose. */
    public function addStep(string $partId = ''): void
    {
        $box = $partId === '' ? 'loose' : $partId;
        $text = $this->stepText[$box] ?? '';
        $added = $this->attempt(['title' => "stepText.{$box}"], fn () => $this->plans->addStep($this->principal(), $this->activityId, $text, $partId === '' ? null : $partId));
        if ($added) {
            unset($this->stepText[$box]);
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
        $this->reading = false;
    }

    public function updatedReply(): void
    {
        $this->reading = false;
    }

    public function readReply(): void
    {
        $this->resetErrorBag('reply');
        $read = PlanMaker::read($this->reply);
        if ($read['parts'] === [] && $read['steps'] === [] && $read['criteria'] === []) {
            $this->reading = false;
            $this->addError('reply', 'No parts, steps or criteria found in it. Paste the AI\'s whole reply, with the marks it wrote.');

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

    /** todo or done for a part or a step; not_yet, partly or met for a criterion. */
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
        $this->resetErrorBag();
    }

    public function saveEdit(): void
    {
        if ($this->editing === null) {
            return;
        }
        if ($this->attempt(['title' => 'editTitle', 'marks' => 'editMarks'], fn () => $this->plans->edit($this->principal(), $this->editing, $this->editTitle, $this->editMarks))) {
            $this->cancelEdit();
            $this->changed();
        }
    }

    public function cancelEdit(): void
    {
        $this->reset('editing', 'editTitle', 'editMarks');
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

        return view('livewire.workspaces.assignment-plan', [
            'plan' => $plan,
            'assignment' => $assignment,
            'progress' => $progress,
            'pace' => $progress->pace($assignment),
            'criteria' => $plan->criteriaScore(),
            'starters' => PlanStarters::ALL,
            'prompt' => $this->aiOpen ? $this->maker->prompt($by, $this->activityId, $this->brief) : '',
            'read' => $read,
        ]);
    }

    /** Runs a change; true when it was made. A refusal goes to the field it names ($fields maps the service's field to a property). */
    private function attempt(array $fields, callable $change): bool
    {
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
