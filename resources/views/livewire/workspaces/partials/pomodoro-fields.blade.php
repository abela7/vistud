{{--
    The Pomodoro fields (App\Livewire\Concerns\PomodoroForm). $choose shows
    the free-or-Pomodoro choice first.
--}}
@php use App\Study\Sessions; @endphp
@if ($choose ?? true)
    <fieldset class="space-y-1">
        <legend class="field-label mb-1">Which clock?</legend>
        <label class="move-option">
            <input type="radio" name="clock" value="free" wire:model.live="clock">
            <span><span class="font-medium">Free</span> <span class="text-fg-muted">· pause and take breaks when you like</span></span>
        </label>
        <label class="move-option">
            <input type="radio" name="clock" value="pomodoro" wire:model.live="clock">
            <span><span class="font-medium">Pomodoro</span> <span class="text-fg-muted">· focus, then a short break; a long one every few rounds</span></span>
        </label>
    </fieldset>
@endif
@if ($clock === 'pomodoro')
    <div class="space-y-4 rounded-lg border border-border p-4">
        <div class="field">
            <label for="pomodoro-preset" class="field-label">Rhythm</label>
            <select id="pomodoro-preset" class="input" wire:model.live="preset">
                @foreach (Sessions::POMODORO_PRESETS as $key => [$name, $focusMinutes, $shortMinutes, $longMinutes, $everyRounds])
                    <option value="{{ $key }}">{{ $name }}: {{ $focusMinutes }} min focus, {{ $shortMinutes }} min break, {{ $longMinutes }} min after {{ $everyRounds }}</option>
                @endforeach
                <option value="custom">Custom</option>
            </select>
        </div>
        @if ($preset === 'custom')
            <div class="grid grid-cols-2 gap-4">
                <x-field name="focus" label="Focus (min)" type="number" inputmode="numeric" min="5" max="120" wire:model="focus" />
                <x-field name="short" label="Short break (min)" type="number" inputmode="numeric" min="1" max="30" wire:model="short" />
                <x-field name="long" label="Long break (min)" type="number" inputmode="numeric" min="5" max="60" wire:model="long" />
                <x-field name="every" label="Long break after" type="number" inputmode="numeric" min="2" max="8" wire:model="every" hint="Focus periods." />
            </div>
        @endif
        <x-checkbox name="auto" label="Start the next focus by itself after a break" wire:model="auto" />
    </div>
@endif
