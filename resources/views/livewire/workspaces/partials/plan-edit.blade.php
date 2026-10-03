{{--
    Changing an item of the plan ($item), in App\Livewire\Workspaces\AssignmentPlan: its name; for a part or a
    criterion its marks; for a part, a step or a milestone its dates and notes; for a part or a step also its
    priority, labels and the person it is for. What is not there for its kind is not asked. Enter saves, Esc cancels.
--}}
@php
    $marks = in_array($item->kind, ['part', 'criterion'], true);
    $dated = in_array($item->kind, ['part', 'step', 'milestone'], true);
    $worked = in_array($item->kind, ['part', 'step'], true);
@endphp
<form wire:submit="saveEdit" novalidate class="plan-edit" x-on:keydown.escape.prevent="$wire.cancelEdit()">
    <div class="field plan-edit-wide">
        <label for="plan-edit-title" class="field-label">Name</label>
        <input id="plan-edit-title" type="text" class="input mt-1" wire:model="editTitle" maxlength="{{ \App\Study\Plans::MAX_TITLE }}" autocomplete="off" autofocus>
        @error('editTitle') <p class="field-error mt-1">{{ $message }}</p> @enderror
    </div>
    @if ($marks)
        <div class="field">
            <label for="plan-edit-marks" class="field-label">{{ $item->kind === 'part' ? 'Weight %' : 'Marks %' }}</label>
            <input id="plan-edit-marks" type="number" class="input plan-marks-input mt-1" wire:model="editMarks" min="1" max="100" inputmode="numeric" placeholder="Optional">
            @error('editMarks') <p class="field-error mt-1">{{ $message }}</p> @enderror
        </div>
    @endif
    @if ($dated)
        @if ($worked)
            <div class="field">
                <label for="plan-edit-start" class="field-label">Start</label>
                <input id="plan-edit-start" type="date" class="input mt-1" wire:model="editStart">
                @error('editStart') <p class="field-error mt-1">{{ $message }}</p> @enderror
            </div>
        @endif
        <div class="field">
            <label for="plan-edit-due" class="field-label">{{ $item->kind === 'milestone' ? 'Day' : 'Due' }}</label>
            <input id="plan-edit-due" type="date" class="input mt-1" wire:model="editDue">
            @error('editDue') <p class="field-error mt-1">{{ $message }}</p> @enderror
        </div>
    @endif
    @if ($worked)
        <div class="field">
            <label for="plan-edit-priority" class="field-label">Priority</label>
            <select id="plan-edit-priority" class="input mt-1" wire:model="editPriority">
                <option value="">None</option>
                @foreach (\App\Study\Plans::PRIORITIES as $value => $word)
                    <option value="{{ $value }}">{{ $word }}</option>
                @endforeach
            </select>
            @error('editPriority') <p class="field-error mt-1">{{ $message }}</p> @enderror
        </div>
        @if ($plan->members !== [])
            <div class="field">
                <label for="plan-edit-member" class="field-label">For</label>
                <select id="plan-edit-member" class="input mt-1" wire:model="editMember">
                    <option value="">Nobody yet</option>
                    @foreach ($plan->members as $member)
                        <option value="{{ $member->id }}">{{ $member->name }}{{ $member->me ? ' (you)' : '' }}</option>
                    @endforeach
                </select>
                @error('editMember') <p class="field-error mt-1">{{ $message }}</p> @enderror
            </div>
        @endif
        <div class="field plan-edit-wide">
            <label for="plan-edit-labels" class="field-label">Labels</label>
            <input id="plan-edit-labels" type="text" class="input mt-1" wire:model="editLabels" autocomplete="off" aria-describedby="plan-edit-labels-hint" placeholder="Like “report, urgent”">
            <p id="plan-edit-labels-hint" class="field-hint mt-1">Up to {{ \App\Study\Plans::MAX_LABELS }}, separated by commas.</p>
            @error('editLabels') <p class="field-error mt-1">{{ $message }}</p> @enderror
        </div>
    @endif
    @if ($dated)
        <div class="field plan-edit-wide">
            <label for="plan-edit-notes" class="field-label">Notes</label>
            <textarea id="plan-edit-notes" class="input mt-1" rows="3" wire:model="editNotes" maxlength="{{ \App\Study\Plans::MAX_NOTES }}"></textarea>
            @error('editNotes') <p class="field-error mt-1">{{ $message }}</p> @enderror
        </div>
    @endif
    <div class="plan-edit-wide flex flex-wrap gap-2">
        <button type="submit" class="btn btn-primary btn-sm">Save</button>
        <button type="button" class="btn btn-ghost btn-sm" wire:click="cancelEdit">Cancel</button>
    </div>
</form>
