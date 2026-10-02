{{--
    Renaming an item of the plan, and giving a part or a criterion its marks ($item; $marks says whether it has them),
    in App\Livewire\Workspaces\AssignmentPlan. Enter saves, Esc cancels.
--}}
<form wire:submit="saveEdit" novalidate class="plan-edit" x-on:keydown.escape.prevent="$wire.cancelEdit()">
    <div class="field min-w-0 flex-1">
        <label for="plan-edit-title" class="sr-only">New name for {{ $item->title }}</label>
        <input id="plan-edit-title" type="text" class="input" wire:model="editTitle" maxlength="{{ \App\Study\Plans::MAX_TITLE }}" autocomplete="off" autofocus>
        @error('editTitle') <p class="field-error mt-1">{{ $message }}</p> @enderror
    </div>
    @if ($marks)
        <div class="field">
            <label for="plan-edit-marks" class="sr-only">Marks, as a percentage</label>
            <input id="plan-edit-marks" type="number" class="input plan-marks-input" wire:model="editMarks" min="1" max="100" inputmode="numeric" placeholder="Marks %">
            @error('editMarks') <p class="field-error mt-1">{{ $message }}</p> @enderror
        </div>
    @endif
    <div class="flex gap-2">
        <button type="submit" class="btn btn-primary btn-sm">Save</button>
        <button type="button" class="btn btn-ghost btn-sm" wire:click="cancelEdit">Cancel</button>
    </div>
</form>
