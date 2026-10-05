{{--
    The course guide (App\Livewire\Workspaces\GuideChat): a talk that sets a course up, or (from Modules) adds its
    modules. The guide asks one thing at a time; the student answers or pastes their module page; what it proposes sits
    under the talk with a tick on every part, and only what is ticked is added. The talk is kept for the visit (the
    session), what was added is in the course.
--}}
@php
    $proposed = $proposal;
    $count = 0;
    if ($proposed !== null) {
        $count = (int) ($ticks['course'] ?? false) + (int) ($ticks['about'] ?? false) + (int) ($ticks['outcomes'] ?? false) + (int) ($ticks['textbook'] ?? false)
            + count(array_filter($ticks['assessment'] ?? [])) + count(array_filter($ticks['modules'] ?? []));
    }
    $when = fn (?string $day) => $day === null ? null : \Illuminate\Support\Carbon::parse($day)->format('j M Y');
@endphp
<div class="guide space-y-5">
    <div role="status" aria-live="polite" class="empty:hidden"><x-toast :message="$notice" :tone="$noticeTone" /></div>

    <section class="guide-talk" aria-labelledby="guide-talk-title">
        <h2 id="guide-talk-title" class="sr-only">The talk</h2>
        <div class="guide-log" role="log" aria-label="Talk with the guide" aria-live="polite" tabindex="0">
            @foreach ($talk as $i => $line)
                <div @class(['guide-line', 'is-you' => $line['from'] === 'you', 'is-done' => $line['from'] === 'done']) wire:key="guide-{{ $i }}">
                    @if ($line['from'] !== 'done')<span class="sr-only">{{ $line['from'] === 'you' ? 'You' : 'Guide' }}: </span>@endif
                    @if ($line['from'] === 'done')<x-icon name="circle-check" class="size-4 shrink-0" />@endif
                    @if ($line['text'] !== '')<p class="whitespace-pre-line break-words">{{ $line['text'] }}</p>@endif
                    @if (($line['pictures'] ?? 0) > 0)
                        <p class="guide-sent-pictures"><x-icon name="image" class="size-4 shrink-0" />{{ $line['pictures'] === 1 ? 'A picture' : $line['pictures'].' pictures' }} went with this.</p>
                    @endif
                </div>
            @endforeach
            <p class="guide-line text-fg-muted" wire:loading wire:target="send" role="status"><span class="ai-dots" aria-hidden="true"><span></span><span></span><span></span></span> Reading…</p>
        </div>
    </section>

    @if ($proposed !== null)
        <section class="guide-proposal question-panel space-y-4" aria-labelledby="guide-proposal-title">
            <div class="panel-head">
                <span class="item-icon" aria-hidden="true"><x-icon name="sparkles" class="size-5" /></span>
                <div class="panel-head-text">
                    <h2 id="guide-proposal-title" class="panel-title">I would add</h2>
                    <p class="panel-hint">Untick what you don't want.</p>
                </div>
            </div>

            @if (($proposed['course'] ?? []) !== [])
                <div class="guide-part">
                    <x-checkbox name="guide-course" id="guide-course" wire:model.live="ticks.course" label="The course details"
                        :hint="collect([$proposed['course']['code'] ?? null, $proposed['course']['term'] ?? null, isset($proposed['course']['starts_on']) ? 'starts '.$when($proposed['course']['starts_on']) : null, isset($proposed['course']['ends_on']) ? 'ends '.$when($proposed['course']['ends_on']) : null])->filter()->implode(' · ')" />
                </div>
            @endif

            @if ($proposed['about'] !== '')
                <div class="guide-part">
                    <x-checkbox name="guide-about" id="guide-about" wire:model.live="ticks.about" label="What the course is about" />
                    <p class="guide-text">{{ $proposed['about'] }}</p>
                </div>
            @endif

            @if ($proposed['outcomes'] !== [])
                <div class="guide-part">
                    <x-checkbox name="guide-outcomes" id="guide-outcomes" wire:model.live="ticks.outcomes" label="What it should teach" />
                    <ul class="guide-list" role="list">
                        @foreach ($proposed['outcomes'] as $outcome)<li>{{ $outcome }}</li>@endforeach
                    </ul>
                </div>
            @endif

            @if ($proposed['assessment'] !== [])
                <fieldset class="guide-part">
                    <legend class="field-label">How it is assessed</legend>
                    <ul class="guide-list is-ticks" role="list">
                        @foreach ($proposed['assessment'] as $i => $item)
                            <li wire:key="guide-assessment-{{ $i }}">
                                <x-checkbox :name="'guide-assessment-'.$i" :id="'guide-assessment-'.$i" wire:model.live="ticks.assessment.{{ $i }}" :label="$item['name']"
                                    :hint="collect([\App\Study\Activities::KINDS[$item['kind']] ?? null, $item['weight'] !== null ? $item['weight'].'%' : null, $item['due_on'] !== null ? 'due '.$when($item['due_on']) : null])->filter()->implode(' · ')" />
                                @if ($item['due_on'] !== null)
                                    <div class="guide-sub">
                                        <x-checkbox :name="'guide-assignment-'.$i" :id="'guide-assignment-'.$i" wire:model.live="ticks.assignments.{{ $i }}" label="Also add it as an assignment" />
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </fieldset>
            @endif

            @if ($proposed['textbook'] !== '')
                <div class="guide-part">
                    <x-checkbox name="guide-textbook" id="guide-textbook" wire:model.live="ticks.textbook" label="The textbook" :hint="$proposed['textbook']" />
                </div>
            @endif

            @if ($proposed['modules'] !== [])
                <fieldset class="guide-part">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <legend class="field-label">Modules ({{ count($proposed['modules']) }})</legend>
                        <span class="flex gap-3 text-sm">
                            <button type="button" class="quiet-link" wire:click="tickModules(true)">All</button>
                            <button type="button" class="quiet-link" wire:click="tickModules(false)">None</button>
                        </span>
                    </div>
                    <ul class="guide-list is-ticks" role="list">
                        @foreach ($proposed['modules'] as $i => $module)
                            <li wire:key="guide-module-{{ $i }}">
                                <x-checkbox :name="'guide-module-'.$i" :id="'guide-module-'.$i" wire:model.live="ticks.modules.{{ $i }}" :label="$module['title']"
                                    :hint="collect([$when($module['starts_on']), $when($module['ends_on'])])->filter()->implode(' – ')" />
                            </li>
                        @endforeach
                    </ul>
                </fieldset>
            @endif

            <div class="guide-actions">
                <x-button type="button" variant="primary" icon="plus" wire:click="add" wire:loading.attr="aria-busy" wire:target="add" busy-label="Adding…" :disabled="$count === 0">Add what is ticked{{ $count > 0 ? " ({$count})" : '' }}</x-button>
                <x-button type="button" wire:click="drop">Not now</x-button>
            </div>
        </section>
    @endif

    <form wire:submit="send" novalidate class="guide-form"
        x-data="{ take(event) { const files = [...((event.clipboardData ?? event.dataTransfer)?.files ?? [])].filter((file) => file.type.startsWith('image/')); if (files.length > 0) { event.preventDefault(); $wire.uploadMultiple('pictures', files); } } }"
        x-on:keydown.ctrl.enter.prevent="$wire.send()" x-on:keydown.meta.enter.prevent="$wire.send()">
        @if ($problem !== null && $errors->isEmpty())
            <x-alert tone="warning">{{ $problem }} <a href="{{ route('settings', ['part' => 'ai']) }}" class="text-link">AI settings</a></x-alert>
        @endif
        <div class="field">
            <label for="guide-text" class="field-label">Your message</label>
            <textarea id="guide-text" class="input" rows="4" wire:model="text" maxlength="{{ \App\Engine\CourseGuide::MAX_MESSAGE }}"
                placeholder="{{ $for === 'modules' ? 'Paste the timetable, tell me the weeks, or ask me to suggest some…' : 'Tell me about the course, or paste its page…' }}" wire:loading.attr="disabled" wire:target="send"
                x-on:paste="take($event)" x-on:drop="take($event)" x-on:dragover.prevent></textarea>
            @error('text')
                <p class="field-error">{{ $message }}@if ($problem !== null) <a href="{{ route('settings', ['part' => 'ai']) }}" class="text-link">AI settings</a>@endif</p>
            @enderror
        </div>

        {{-- Pictures of the course page or the timetable: a screenshot pasted, dropped or chosen goes with the next message. --}}
        @if ($pictures !== [])
            <ul class="chat-files guide-pictures" role="list" aria-label="Pictures to send">
                @foreach ($pictures as $i => $picture)
                    <li class="chat-file" wire:key="guide-picture-{{ $i }}">
                        @if (method_exists($picture, 'isPreviewable') && $picture->isPreviewable())
                            <img src="{{ $picture->temporaryUrl() }}" alt="" class="guide-thumb">
                        @else
                            <x-icon name="image" class="size-4 shrink-0" />
                        @endif
                        <span class="truncate">{{ $picture->getClientOriginalName() }}</span>
                        <button type="button" class="chat-file-remove" wire:click="removePicture({{ $i }})" aria-label="Take {{ $picture->getClientOriginalName() }} off"><x-icon name="x" class="size-3.5" /></button>
                    </li>
                @endforeach
            </ul>
        @endif
        <p class="guide-line text-fg-muted" wire:loading wire:target="pictures" role="status"><x-icon name="loader-circle" class="size-4 animate-spin" />Getting the picture…</p>
        @error('pictures')<p class="field-error">{{ $message }}</p>@enderror
        @error('pictures.*')<p class="field-error">{{ $message }}</p>@enderror

        <div class="guide-actions">
            <x-button type="submit" variant="primary" icon="arrow-right" wire:loading.attr="aria-busy" wire:target="send" busy-label="Reading…">Send</x-button>
            <label class="btn btn-secondary chat-upload">
                <x-icon name="paperclip" class="size-4" />Attach pictures
                <input type="file" class="sr-only" multiple accept="image/png,image/jpeg,image/webp,image/gif" wire:model="pictures">
            </label>
            @if ($toModules)
                <a href="{{ route('workspaces.show', [$workspace->id, 'modules']) }}" class="btn btn-secondary"><x-icon name="layers" class="size-4" />Go to Modules</a>
            @endif
            <a href="{{ route('workspaces.show', $workspace->id) }}" class="btn btn-secondary">I'm done for now</a>
        </div>
        <p class="text-sm text-fg-muted">Ctrl + Enter sends. You can paste or drop a screenshot of the course page{{ $for === 'modules' ? ' or timetable' : '' }} into the box. Nothing is added until you tick it and press Add.</p>
    </form>
</div>
