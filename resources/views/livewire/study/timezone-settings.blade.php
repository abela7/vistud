{{--
    A student's time zone (App\Livewire\Study\TimezoneSettings), one folded panel on the Security part of Settings:
    the zone they have (the saved one) and the time there now in its status line, the list of every zone by region, Save, and the
    zone this browser reports (read here with Alpine) with one press to take it. That line shows only when the browser's
    zone is one the list knows and isn't the one saved already. Hints are one line (DESIGN.md §5.10).
--}}
<div>
    <div role="status" aria-live="polite" class="empty:hidden">
        <x-toast :message="$notice" :tone="$noticeTone" />
    </div>

    <x-accordion id="timezone" title="Time zone" icon="globe" :open="true">
        <x-slot:status>{{ $saved }} · {{ $now }} there now</x-slot:status>

        <form wire:submit="save" novalidate class="space-y-4"
            x-data="{ detected: '', known: false, init() {
                try { this.detected = Intl.DateTimeFormat().resolvedOptions().timeZone ?? ''; } catch { this.detected = ''; }
                this.known = this.detected !== '' && [...this.$refs.zones.options].some((option) => option.value === this.detected);
            } }">
            <div class="field space-y-1.5">
                <label for="timezone-field" class="field-label">Your time zone</label>
                <select id="timezone-field" class="input" wire:model="timezone" x-ref="zones" autocomplete="off"
                    aria-describedby="timezone-field-hint{{ $errors->has('timezone') ? ' timezone-field-error' : '' }}"
                    @if ($errors->has('timezone')) aria-invalid="true" @endif>
                    @foreach ($regions as $region => $zones)
                        <optgroup label="{{ $region }}">
                            @foreach ($zones as $zone)
                                <option value="{{ $zone }}">{{ $zone }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                <p id="timezone-field-hint" class="field-hint">Your days, due dates and streaks follow it.</p>
                @error('timezone')
                    <p id="timezone-field-error" class="field-error"><x-icon name="circle-alert" class="mt-0.5 size-4" /><span>{{ $message }}</span></p>
                @enderror
            </div>

            <p class="settings-note" x-show="known && detected !== $wire.timezone" x-cloak>
                <x-icon name="earth" class="size-4" />
                <span>Detected: <strong x-text="detected"></strong></span>
                <button type="button" class="text-link" x-on:click="$wire.useDetected(detected)" wire:loading.attr="disabled" wire:target="useDetected">Use this</button>
            </p>

            <div class="acc-actions">
                <x-button type="submit" variant="primary" icon="check" wire:loading.attr="aria-busy" wire:target="save" busy-label="Saving…">Save</x-button>
            </div>
        </form>
    </x-accordion>
</div>
