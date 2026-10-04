{{--
    The New course page (App\Livewire\Workspaces\CourseNew): the name, how the course looks (with a live preview), a few
    details to fill in or leave, and how to set it up: the AI's guide asks and adds what is approved, or the student fills
    the course in on its own page. Picking a colour or an icon changes the preview in the browser, without a request.
--}}
<form wire:submit="create" novalidate class="course-new" x-data="{ name: $wire.entangle('name'), colour: $wire.entangle('colour'), icon: $wire.entangle('icon'), code: $wire.entangle('code'), term: $wire.entangle('term') }">
    <div class="course-new-main space-y-5">
        <section class="question-panel space-y-5" aria-labelledby="new-course-name">
            <div class="panel-head">
                <span class="item-icon" aria-hidden="true"><x-icon name="graduation-cap" class="size-5" /></span>
                <div class="panel-head-text">
                    <h2 id="new-course-name" class="panel-title">The course</h2>
                    <p class="panel-hint">Only the name is needed.</p>
                </div>
            </div>

            <x-field name="name" label="Name" wire:model="name" maxlength="80" autocomplete="off" hint="Like Operating Systems, Biology or Spanish." autofocus />

            <fieldset class="space-y-2">
                <legend class="field-label">Colour</legend>
                <div class="flex flex-wrap gap-2.5">
                    @foreach ($colours as $option)
                        <label class="swatch ws-colour-{{ $option }}" title="{{ ucfirst($option) }}">
                            <input type="radio" class="sr-only" name="colour" value="{{ $option }}" wire:model="colour">
                            <x-icon name="check" class="size-4" />
                            <span class="sr-only">{{ ucfirst($option) }}</span>
                        </label>
                    @endforeach
                </div>
                @error('colour')<p class="field-error">{{ $message }}</p>@enderror
            </fieldset>

            <fieldset class="space-y-2">
                <legend class="field-label">Icon</legend>
                <div class="flex flex-wrap gap-2" :class="'ws-colour-' + colour">
                    @foreach ($icons as $option)
                        <label class="icon-option" title="{{ ucfirst(str_replace('-', ' ', $option)) }}">
                            <input type="radio" class="sr-only" name="icon" value="{{ $option }}" wire:model="icon">
                            <x-icon :name="$option" class="size-5" />
                            <span class="sr-only">{{ ucfirst(str_replace('-', ' ', $option)) }}</span>
                        </label>
                    @endforeach
                </div>
                @error('icon')<p class="field-error">{{ $message }}</p>@enderror
            </fieldset>
        </section>

        <details class="question-panel course-new-more" @if ($errors->hasAny(['code', 'term', 'startsOn', 'endsOn'])) open @endif>
            <summary class="course-new-summary"><x-icon name="chevron-right" class="size-4" />Details <span class="text-fg-muted">(optional)</span></summary>
            <div class="grid gap-4 pt-4 sm:grid-cols-2">
                <x-field name="code" label="Course code" wire:model="code" maxlength="20" autocomplete="off" />
                <x-field name="term" label="Term" wire:model="term" maxlength="40" autocomplete="off" hint="Like Autumn 2026." />
                <x-field name="startsOn" label="Starts" type="date" wire:model="startsOn" />
                <x-field name="endsOn" label="Ends" type="date" wire:model="endsOn" />
            </div>
        </details>

        <section class="question-panel space-y-3" role="radiogroup" aria-labelledby="new-course-how">
            <h2 id="new-course-how" class="panel-title">How do you want to set it up?</h2>
            <div class="choice-grid">
                <label class="choice-card">
                    <input type="radio" class="sr-only" name="how" value="guide" wire:model="how" @disabled($notReady !== null)>
                    <span class="item-icon" aria-hidden="true"><x-icon name="sparkles" class="size-5" /></span>
                    <span class="min-w-0 flex-1">
                        <span class="choice-title">Guide me</span>
                        <span class="choice-hint">The AI asks a few questions and adds what you approve.</span>
                        @if ($notReady !== null)
                            <span class="choice-hint">{{ $notReady }} <a href="{{ route('settings', ['part' => 'ai']) }}" class="text-link">AI settings</a></span>
                        @endif
                    </span>
                    <x-icon name="circle-check" class="choice-mark size-5" />
                </label>
                <label class="choice-card">
                    <input type="radio" class="sr-only" name="how" value="myself" wire:model="how">
                    <span class="item-icon" aria-hidden="true"><x-icon name="pencil" class="size-5" /></span>
                    <span class="min-w-0 flex-1">
                        <span class="choice-title">I'll do it myself</span>
                        <span class="choice-hint">Fill in what you like on the course page, in your own time.</span>
                    </span>
                    <x-icon name="circle-check" class="choice-mark size-5" />
                </label>
            </div>
        </section>
    </div>

    <aside class="course-new-side" aria-label="How it will look">
        <p class="field-label">How it will look</p>
        <div class="workspace-card" :class="'ws-colour-' + colour">
            <div class="flex min-w-0 items-start gap-3">
                <span class="ws-chip size-11" aria-hidden="true">
                    @foreach ($icons as $option)
                        <span x-show="icon === '{{ $option }}'" @if ($option !== $icon) x-cloak @endif><x-icon :name="$option" class="size-5" /></span>
                    @endforeach
                </span>
                <div class="min-w-0">
                    <p class="font-semibold tracking-tight break-words" x-text="name.trim() || 'Your course'">Your course</p>
                    <p class="item-meta break-words" x-show="code.trim() || term.trim()" x-text="[code.trim(), term.trim()].filter(Boolean).join(' · ')" x-cloak></p>
                </div>
            </div>
            <div class="mt-auto border-t border-divider pt-3 text-xs text-fg-subtle">Empty course</div>
        </div>
    </aside>

    <div class="course-new-actions">
        <a href="{{ route('home') }}" class="btn btn-secondary">Cancel</a>
        <x-button type="submit" variant="primary" icon="plus" wire:loading.attr="aria-busy" wire:target="create" busy-label="Creating…">Create course</x-button>
    </div>
</form>
