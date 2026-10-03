<?php

namespace App\Livewire\Concerns;

use App\Platform\Errors\Conflict;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\PlanItem;
use Livewire\Attributes\Locked;

/**
 * Working on an assignment's plan from a screen (App\Livewire\Workspaces\AssignmentPlan, and a section's own page,
 * App\Livewire\Workspaces\SectionPage): adding tasks and sub-tasks, ticking them, changing an item's details,
 * moving and deleting. The component has $plans (App\Study\Plans), $activityId, notify()
 * (App\Livewire\Concerns\Notices) and principal(). The service checks everything.
 */
trait EditsPlan
{
    /** What is typed in each "Add a task" box, by part ID; `loose` is the box for tasks outside any section. */
    public array $stepText = [];

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
        $kept = null;
        if ($this->attempt([], function () use ($id, &$kept) {
            $kept = $this->plans->delete($this->principal(), $id);
        })) {
            if ($kept !== null) {
                $this->notify("Deleted. Its folder “{$kept}” stays in the assignment's folder, with what is in it.");
            }
            $this->changed();
        }
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
}
