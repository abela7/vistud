{{--
    How the assistant should teach (App\Livewire\Concerns\TeachingForm):
    the four choices, each with what it asks of the assistant, plus optional custom Do & Don't rules.
--}}
@php use App\Study\Tutoring; @endphp
<div class="space-y-4">
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

    <div class="pt-3 border-t border-border-subtle">
        <div class="flex items-center justify-between mb-2">
            <span class="field-label mb-0">Subject plugins</span>
            <span class="text-3xs text-fg-subtle">Optional domain tools &amp; formatting</span>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-2">
            @foreach (\App\Study\Plugins::all() as $p)
                @php $isPluginActive = in_array($p->id, $plugins, true); @endphp
                <button type="button"
                    wire:click="toggleTeachingPlugin('{{ $p->id }}')"
                    class="flex flex-col text-left p-2.5 rounded-xl border transition-colors cursor-pointer {{ $isPluginActive ? 'border-accent bg-accent-subtle text-fg' : 'border-border bg-surface text-fg-muted hover:border-border-strong hover:bg-hover' }}">
                    <div class="flex items-center justify-between w-full gap-2">
                        <div class="flex items-center gap-1.5 min-w-0">
                            <x-icon name="{{ $p->icon }}" class="size-4 shrink-0 {{ $isPluginActive ? 'text-accent' : 'text-fg-subtle' }}" />
                            <span class="font-medium text-xs truncate {{ $isPluginActive ? 'text-accent' : 'text-fg' }}">{{ $p->name }}</span>
                        </div>
                        <span class="size-2 rounded-full shrink-0 {{ $isPluginActive ? 'bg-success' : 'bg-fg-subtle opacity-40' }}"></span>
                    </div>
                    <span class="text-3xs text-fg-subtle mt-1 line-clamp-1">{{ $p->badge }} · {{ $p->description }}</span>
                </button>
            @endforeach
        </div>
    </div>

    <div class="pt-3 border-t border-border-subtle" x-data="{ expanded: false }">
        <div class="flex items-center justify-between gap-3 p-3 rounded-xl border border-border bg-surface">
            <div class="min-w-0 space-y-0.5">
                <div class="flex items-center gap-1.5">
                    <span class="inline-block size-2 rounded-full {{ (! empty($customDo) || ! empty($customDont)) ? 'bg-success' : 'bg-fg-subtle' }}"></span>
                    <span class="text-xs font-semibold text-fg uppercase tracking-wider">Custom rules (Do &amp; Don't)</span>
                    @if (! empty($customDo) || ! empty($customDont))
                        <span class="text-3xs px-1.5 py-0.5 rounded-full bg-accent-subtle text-accent font-medium">Active</span>
                    @endif
                </div>
                <p class="text-xs text-fg-muted truncate">
                    @if (! empty($customDo) || ! empty($customDont))
                        {{ ! empty($customDo) ? 'Do: '.Str::limit($customDo, 40) : 'Don\'t: '.Str::limit($customDont, 40) }}
                    @else
                        Write numbered rules (1, 2, 3...) for what the tutor should do and avoid.
                    @endif
                </p>
            </div>

            @if (method_exists($this, 'editCustomRules'))
                <button type="button" class="btn btn-secondary btn-sm text-xs shrink-0" wire:click="editCustomRules" title="Open full note editor popup">
                    <x-icon name="notebook-pen" class="size-3.5 text-accent" />
                    <span>Customize (1, 2, 3…)</span>
                </button>
            @else
                <button type="button" class="btn btn-secondary btn-sm text-xs shrink-0" x-on:click="expanded = ! expanded">
                    <span x-text="expanded ? 'Hide' : 'Edit rules'"></span>
                </button>
            @endif
        </div>

        @if (! method_exists($this, 'editCustomRules'))
            <div x-show="expanded" x-cloak class="mt-3 space-y-3">
                <div class="field">
                    <label for="teaching-custom-do" class="field-label flex items-center gap-1.5">
                        <span class="inline-block size-1.5 rounded-full bg-success"></span>
                        <span>What to do (e.g. 1. Step by step, 2. Code examples)</span>
                    </label>
                    <textarea id="teaching-custom-do"
                        class="input text-sm"
                        rows="3"
                        maxlength="{{ \App\Study\Tutoring::MAX_CUSTOM }}"
                        wire:model="customDo"
                        placeholder="1. Break concepts into step-by-step points&#10;2. Always provide concrete code examples..."></textarea>
                    @error('customDo') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div class="field">
                    <label for="teaching-custom-dont" class="field-label flex items-center gap-1.5">
                        <span class="inline-block size-1.5 rounded-full bg-danger"></span>
                        <span>What NOT to do</span>
                    </label>
                    <textarea id="teaching-custom-dont"
                        class="input text-sm"
                        rows="3"
                        maxlength="{{ \App\Study\Tutoring::MAX_CUSTOM }}"
                        wire:model="customDont"
                        placeholder="1. Never give full answers immediately&#10;2. Avoid academic jargon without defining it..."></textarea>
                    @error('customDont') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>
        @endif
    </div>
</div>
