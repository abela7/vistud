{{--
    A new section of an assignment's plan, or changing one (App\Livewire\Workspaces\SectionForm): its name, its
    weight out of 100 (with what the other sections leave), the day it should be done by, and a few words about it.
--}}
@php
    $new = $sectionId === null;
    $back = $new
        ? route('workspaces.assignments.show', [$workspaceId, $activityId])
        : route('workspaces.assignments.sections.show', [$workspaceId, $activityId, $sectionId]);
@endphp
<div class="mx-auto max-w-3xl space-y-5">
    <x-workspace.section-header :workspace="$workspace" :title="$new ? 'New section' : 'Edit section'" :back-href="$back" :back-to="$new ? $assignment->title : $name" :eyebrow="$workspace->name.' · '.$assignment->title" />

    <form wire:submit="save" novalidate class="question-panel space-y-5" aria-label="{{ $new ? 'New section' : 'Edit section' }}">
        <div class="field">
            <label for="section-name" class="field-label">Name</label>
            <input id="section-name" type="text" class="input mt-2" wire:model="name" maxlength="{{ \App\Study\Plans::MAX_TITLE }}" autocomplete="off" placeholder="Like “Literature review” or “Question 2”" @if ($new) autofocus @endif>
            @error('name') <p class="field-error mt-2">{{ $message }}</p> @enderror
        </div>

        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <div class="field">
                <label for="section-weight" class="field-label">Weight <span class="font-normal text-fg-muted">(out of 100)</span></label>
                <div class="mt-2 flex items-center gap-2">
                    <input id="section-weight" type="number" class="input" wire:model="weight" min="1" max="100" inputmode="numeric" placeholder="Like 30" aria-describedby="section-weight-hint">
                    <span class="text-fg-muted">%</span>
                </div>
                <p id="section-weight-hint" class="field-hint mt-2">{{ $left }}% is left for this section. Leave it empty to share what is left with the others.</p>
                @error('weight') <p class="field-error mt-2">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="section-due" class="field-label">Done by <span class="font-normal text-fg-muted">(optional)</span></label>
                <input id="section-due" type="date" class="input mt-2" wire:model="dueOn">
                @error('dueOn') <p class="field-error mt-2">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="field">
            <label for="section-description" class="field-label">About it <span class="font-normal text-fg-muted">(optional)</span></label>
            <textarea id="section-description" class="input mt-2" rows="3" wire:model="description" maxlength="{{ \App\Study\Plans::MAX_NOTES }}" placeholder="What this section is for, what the brief asks of it"></textarea>
            @error('description') <p class="field-error mt-2">{{ $message }}</p> @enderror
        </div>

        <div class="flex flex-wrap justify-end gap-3">
            <a href="{{ $back }}" class="btn btn-secondary">Cancel</a>
            <x-button type="submit" variant="primary" wire:loading.attr="aria-busy" wire:target="save" :busy-label="$new ? 'Adding…' : 'Saving…'">{{ $new ? 'Add the section' : 'Save' }}</x-button>
        </div>
    </form>
</div>
