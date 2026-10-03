<?php

namespace App\Livewire\Workspaces;

use App\Identity\PrincipalFactory;
use App\Platform\Access\Principal;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\Activities;
use App\Study\Plans;
use App\Study\Workspaces;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A section of an assignment's plan, made or changed on a page of its own (the owner's review, 2026-10-04): its
 * name, its weight out of 100 (with what the other sections leave), the day it should be done by and a few words
 * about it. Saving goes to the section's own page. The IDs are locked; the service checks everything.
 */
final class SectionForm extends Component
{
    #[Locked]
    public string $workspaceId;

    #[Locked]
    public string $activityId;

    /** The section being changed, or null for a new one. */
    #[Locked]
    public ?string $sectionId = null;

    public string $name = '';

    public string $weight = '';

    public string $dueOn = '';

    public string $description = '';

    private Plans $plans;

    private Activities $activities;

    private Workspaces $workspaces;

    private PrincipalFactory $principals;

    public function boot(Plans $plans, Activities $activities, Workspaces $workspaces, PrincipalFactory $principals): void
    {
        [$this->plans, $this->activities, $this->workspaces, $this->principals] = [$plans, $activities, $workspaces, $principals];
    }

    public function mount(string $workspaceId, string $activityId, ?string $sectionId = null): void
    {
        [$this->workspaceId, $this->activityId, $this->sectionId] = [$workspaceId, $activityId, $sectionId];
        if ($sectionId !== null) {
            $section = $this->plans->get($this->principal(), $activityId)->item($sectionId) ?? throw new NotFound;
            [$this->name, $this->weight, $this->dueOn, $this->description] = [$section->title, (string) $section->weight, (string) $section->dueOn, (string) $section->notes];
        }
    }

    public function save(): void
    {
        $this->resetErrorBag();
        $by = $this->principal();
        $fields = ['title' => $this->name, 'marks' => $this->weight, 'due_on' => $this->dueOn, 'notes' => $this->description];
        try {
            if ($this->sectionId === null) {
                $made = $this->plans->addPart($by, $this->activityId, $this->name, $this->weight);
                $this->plans->update($by, $made->id, ['due_on' => $this->dueOn, 'notes' => $this->description]);
                $this->sectionId = $made->id;
            } else {
                $this->plans->update($by, $this->sectionId, $fields);
            }
        } catch (Unprocessable $e) {
            $map = ['title' => 'name', 'marks' => 'weight', 'due_on' => 'dueOn', 'notes' => 'description'];
            foreach ($e->details['fields'] ?? [] as $field => $messages) {
                $this->addError($map[$field] ?? 'name', $messages[0]);
            }

            return;
        } catch (Conflict $e) {
            $this->addError('name', $e->getMessage());

            return;
        }
        $this->redirectRoute('workspaces.assignments.sections.show', [$this->workspaceId, $this->activityId, $this->sectionId], navigate: true);
    }

    public function render(): View
    {
        $by = $this->principal();
        $plan = $this->plans->get($by, $this->activityId);
        $own = $this->sectionId === null ? 0 : (int) ($plan->item($this->sectionId)?->weight ?? 0);

        return view('livewire.workspaces.section-form', [
            'workspace' => $this->workspaces->find($by, $this->workspaceId),
            'assignment' => $this->activities->find($by, $this->activityId),
            'left' => max(0, 100 - ($plan->weights()['given'] - $own)),
        ]);
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
