{{--
    How the assistant should teach (App\Livewire\Concerns\TeachingForm):
    the four choices, each with what it asks of the assistant.
--}}
@php use App\Study\Tutoring; @endphp
<div class="grid gap-4 sm:grid-cols-2">
    @foreach (['method' => 'method', 'check_ins' => 'checkIns', 'quiz' => 'quiz', 'pace' => 'pace'] as $key => $property)
        <div class="field">
            <label for="teaching-{{ $key }}" class="field-label">{{ Tutoring::QUESTIONS[$key] }}</label>
            <select id="teaching-{{ $key }}" class="input" wire:model.live="{{ $property }}">
                @foreach (Tutoring::CHOICES[$key] as $value => [$label])
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            @error($property) <p class="field-error">{{ $message }}</p> @enderror
        </div>
    @endforeach
</div>
