{{--
    Setting a course up (App\Livewire\Workspaces\CourseSetup): What is this course? (a syllabus the reader turns into
    the course's profile and a list of modules to tick, or written by hand) and How do you like to learn? (four
    short questions). Each step can be skipped.
--}}
@php
    $ticked = count(array_filter($moduleTicks));
    $dates = fn (array $module) => implode(' – ', array_filter([
        $module['starts_on'] ? \Illuminate\Support\Carbon::parse($module['starts_on'])->format('j M') : null,
        $module['ends_on'] ? \Illuminate\Support\Carbon::parse($module['ends_on'])->format('j M') : null,
    ]));
@endphp
<div>
    <dialog id="course-setup" class="modal" aria-labelledby="course-setup-title"
        wire:ignore.self
        x-data
        x-init="@if ($openOnLoad) $el.showModal() @endif"
        x-on:course-setup-dialog-open.window="$el.open || $el.showModal()"
        x-on:close="$wire.cancel()"
        x-on:click="$event.target === $el && $el.close()">
        <div class="modal-panel modal-panel-wide">
            <div class="modal-head">
                <div class="min-w-0 flex-1">
                    @if ($flow)
                        <p class="text-sm text-fg-muted">Step {{ $step === 'about' ? 2 : 3 }} of 3</p>
                    @endif
                    <h2 id="course-setup-title" class="text-lg font-semibold">{{ $step === 'about' ? 'What is this course?' : 'How do you like to learn?' }}</h2>
                </div>
                <button type="button" class="topbar-button -mt-1 -mr-2 shrink-0" aria-label="Close" x-on:click="$el.closest('dialog').close()">
                    <x-icon name="x" />
                </button>
            </div>

            @if ($step === 'about')
                <div class="space-y-4 px-5 pt-2">
                    @if ($reading)
                        <div class="flex items-center gap-3 rounded-lg bg-surface-sunken p-4" role="status" wire:poll.3s="check">
                            <x-icon name="loader-circle" class="size-5 animate-spin" />
                            <span class="font-medium">Reading the syllabus…</span>
                        </div>
                    @else
                        @if ($notReady)
                            <x-alert tone="warning">{{ $notReady }} <a href="{{ route('engine.settings') }}" class="text-link">AI settings</a></x-alert>
                        @else
                            <div class="field">
                                <label for="syllabus-file" class="field-label">Drop the syllabus</label>
                                <input id="syllabus-file" type="file" class="input" wire:model="file" accept="{{ \App\Study\FileTypes::accept() }}">
                                <p class="field-hint">PDF, Word or PowerPoint.</p>
                            </div>
                            <div class="field">
                                <label for="syllabus-text" class="field-label">Or paste its text</label>
                                <textarea id="syllabus-text" class="input" rows="3" wire:model="syllabusText"></textarea>
                                @error('syllabus') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <x-button variant="secondary" icon="wand-sparkles" wire:click="read" wire:loading.attr="aria-busy" wire:target="read,file" busy-label="Reading…">Read it</x-button>
                            </div>
                        @endif
                        @if ($problemText)
                            <x-alert tone="danger">{{ $problemText }}</x-alert>
                        @endif
                        @unless ($writing)
                            <button type="button" class="quiet-link" wire:click="write">Write it myself</button>
                        @endunless
                    @endif

                    @if ($writing && ! $reading)
                        <div class="field">
                            <label for="course-about" class="field-label">About</label>
                            <textarea id="course-about" class="input" rows="3" maxlength="1200" wire:model="about"></textarea>
                            @error('about') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <div class="field">
                            <label for="course-outcomes" class="field-label">What it should teach</label>
                            <textarea id="course-outcomes" class="input" rows="4" wire:model="outcomes"></textarea>
                            <p class="field-hint">One line each.</p>
                            @error('outcomes') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <x-field name="textbook" label="Textbook" wire:model="textbook" maxlength="200" autocomplete="off" />

                        <fieldset class="space-y-2">
                            <legend class="field-label">How it is assessed</legend>
                            @foreach ($assessment as $index => $row)
                                <div class="grid grid-cols-2 items-end gap-2 sm:grid-cols-12" wire:key="assessment-{{ $index }}">
                                    <div class="col-span-2 sm:col-span-3">
                                        <label for="assessment-name-{{ $index }}" class="sr-only">Name</label>
                                        <input id="assessment-name-{{ $index }}" class="input" placeholder="Midterm" maxlength="80" wire:model="assessment.{{ $index }}.name" autocomplete="off">
                                    </div>
                                    <div class="sm:col-span-3">
                                        <label for="assessment-kind-{{ $index }}" class="sr-only">Kind</label>
                                        <select id="assessment-kind-{{ $index }}" class="input" wire:model="assessment.{{ $index }}.kind">
                                            @foreach ($kinds as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label for="assessment-weight-{{ $index }}" class="sr-only">Weight in percent</label>
                                        <input id="assessment-weight-{{ $index }}" class="input" inputmode="numeric" placeholder="%" maxlength="3" wire:model="assessment.{{ $index }}.weight" autocomplete="off">
                                    </div>
                                    <div class="col-span-2 sm:col-span-3">
                                        <label for="assessment-date-{{ $index }}" class="sr-only">Due date</label>
                                        <input id="assessment-date-{{ $index }}" type="date" class="input" wire:model="assessment.{{ $index }}.due_on">
                                    </div>
                                    <div class="col-span-2 flex items-center justify-between gap-2 sm:col-span-1 sm:justify-end">
                                        <button type="button" class="topbar-button" wire:click="removeRow({{ $index }})" aria-label="Remove {{ $row['name'] !== '' ? $row['name'] : 'this item' }}"><x-icon name="trash-2" class="size-4" /></button>
                                    </div>
                                    @if ($row['activity_id'] !== null)
                                        <p class="col-span-2 text-sm text-fg-muted sm:col-span-12"><x-icon name="circle-check" class="size-3.5 inline" /> Added as an assignment</p>
                                    @elseif (trim((string) $row['due_on']) !== '')
                                        <div class="col-span-2 sm:col-span-12">
                                            <x-checkbox :name="'assessmentTicks'.$index" :id="'assessment-tick-'.$index" label="Add as an assignment" wire:model="assessmentTicks.{{ $index }}" />
                                        </div>
                                    @endif
                                    @error('assessment.'.$index) <p class="field-error col-span-2 sm:col-span-12">{{ $message }}</p> @enderror
                                </div>
                            @endforeach
                            <x-button size="sm" icon="plus" wire:click="addRow">Add an item</x-button>
                        </fieldset>

                        @if ($proposed !== [])
                            <fieldset class="space-y-1">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <legend class="field-label">Modules found ({{ count($proposed) }})</legend>
                                    <span class="flex gap-3 text-sm">
                                        <button type="button" class="quiet-link" wire:click="tickModules(true)">All</button>
                                        <button type="button" class="quiet-link" wire:click="tickModules(false)">None</button>
                                    </span>
                                </div>
                                <ul class="item-list" role="list">
                                    @foreach ($proposed as $index => $module)
                                        <li class="px-3" wire:key="module-{{ $index }}">
                                            <x-checkbox :name="'moduleTicks'.$index" :id="'module-tick-'.$index" :label="$module['title'].($dates($module) !== '' ? ' · '.$dates($module) : '')" wire:model.live="moduleTicks.{{ $index }}" />
                                        </li>
                                    @endforeach
                                </ul>
                            </fieldset>
                        @endif
                    @endif
                </div>
                <div class="modal-actions">
                    @if ($proposed !== [] && ! $reading)
                        <x-button variant="ghost" wire:click="dismiss">Not these</x-button>
                    @endif
                    @if ($flow)
                        <x-button wire:click="next">Skip</x-button>
                    @else
                        <x-button x-on:click="$el.closest('dialog').close()">Close</x-button>
                    @endif
                    @if ($writing && ! $reading)
                        <x-button variant="primary" icon="check" wire:click="add" wire:loading.attr="aria-busy" wire:target="add" busy-label="Saving…">{{ $proposed !== [] && $ticked > 0 ? 'Add '.$ticked.($ticked === 1 ? ' module' : ' modules') : 'Save' }}</x-button>
                    @endif
                </div>
            @else
                <div class="space-y-4 px-5 pt-2">
                    <fieldset class="space-y-2">
                        <legend class="field-label">Explain with</legend>
                        <div class="segmented">
                            @foreach ($chips['explain'] as $value => [$label])
                                <label class="segmented-option">
                                    <input type="checkbox" class="sr-only" value="{{ $value }}" wire:model="explain">
                                    <span>{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                    @foreach (['pace' => 'Pace', 'check' => 'Check me', 'goal' => 'My goal'] as $key => $legend)
                        <fieldset class="space-y-2">
                            <legend class="field-label">{{ $legend }}</legend>
                            <div class="segmented">
                                @foreach ($chips[$key] as $value => [$label])
                                    <label class="segmented-option">
                                        <input type="radio" class="sr-only" name="learn-{{ $key }}" value="{{ $value }}" wire:model="{{ $key }}">
                                        <span>{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                            @error($key) <p class="field-error">{{ $message }}</p> @enderror
                        </fieldset>
                    @endforeach
                    <div class="field">
                        <label for="learn-note" class="field-label">Anything else the tutor should know (optional)</label>
                        <textarea id="learn-note" class="input" rows="2" maxlength="300" wire:model="note"></textarea>
                        @error('note') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="modal-actions">
                    @if ($flow)
                        <x-button wire:click="next">Skip</x-button>
                    @else
                        <x-button x-on:click="$el.closest('dialog').close()">Close</x-button>
                    @endif
                    <x-button variant="primary" icon="check" wire:click="saveLearn" wire:loading.attr="aria-busy" wire:target="saveLearn" busy-label="Saving…">Done</x-button>
                </div>
            @endif
        </div>
    </dialog>
</div>
