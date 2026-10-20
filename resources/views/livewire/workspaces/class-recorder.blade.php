{{--
    One class recording and one class note (App\Livewire\Workspaces\ClassRecorder).
    A break pauses the same recording. A mark is a stretch inside the one note.
    A take that never finished is kept or discarded here; nothing is lost silently.
--}}
@php
    use App\Study\ClassRecordingDetails;

    $uid = $this->getId();
@endphp
<div class="space-y-6">
    <div class="card space-y-5 p-5">
        <div class="space-y-1">
            <p class="text-sm font-medium">{{ $class->statusLabel() }}@if ($class->durationSeconds > 0) · {{ ClassRecordingDetails::length($class->durationSeconds) }}@endif</p>
            @unless ($class->hasConsent())
                <p class="max-w-xl text-sm text-fg-muted">Only record this class when the people in the room have agreed. The audio stays in your account.</p>
            @else
                <p class="max-w-xl text-sm text-fg-muted">One recording for this class. Pause for a break, finish when the class is over. Keep this screen open while you record.</p>
            @endunless
        </div>

        @error('recording') <p class="field-error" role="alert">{{ $message }}</p> @enderror

        @unless ($class->hasConsent())
            <x-button variant="primary" icon="mic" wire:click="allow" busy-label="Saving…">I have permission to record</x-button>
        @else
            <div wire:ignore x-data="classRecorder" data-chunk-base="{{ $chunkBase }}" data-offset="{{ $class->durationSeconds }}" class="space-y-3">
                <p class="text-sm text-fg-muted" x-show="!canRecord" x-cloak>This browser cannot record. Open this page in Chrome, Edge, Firefox or Safari.</p>
                <p class="field-error" x-show="error !== ''" x-text="error" x-cloak role="alert"></p>
                <button type="button" class="btn btn-primary" x-show="canRecord && !recording" x-cloak x-on:click="record()">
                    <x-icon name="mic" class="size-4" /> {{ $class->durationSeconds > 0 ? 'Record more' : 'Record' }}
                </button>

                {{-- While recording, the recorder has the whole screen: the clock, Pause and Finish, and your notes. --}}
                <template x-teleport="body">
                    <div x-show="recording" x-cloak class="fixed inset-0 z-[70] flex flex-col gap-4 overflow-y-auto bg-surface p-4 sm:p-8" role="dialog" aria-modal="true" aria-label="Recording this class">
                        <div class="mx-auto flex w-full max-w-2xl flex-1 flex-col gap-4">
                            <div class="flex flex-wrap items-center justify-between gap-3" x-show="!compact">
                                <p class="text-sm font-medium" x-show="!paused" role="status">
                                    <span class="mr-1 inline-block size-2 animate-pulse rounded-full bg-danger align-middle" aria-hidden="true"></span> Recording
                                </p>
                                <p class="text-sm font-medium" x-show="paused" x-cloak role="status">Paused</p>
                                <p class="text-3xl font-semibold tabular-nums" x-text="clock" aria-label="Time recorded"></p>
                            </div>
                            <p class="field-error" x-show="error !== ''" x-text="error" x-cloak role="alert"></p>
                            <div class="flex flex-wrap items-center gap-3" x-show="!compact">
                                <button type="button" class="btn btn-secondary btn-lg" x-show="canPause && !paused" x-on:click="pause()">
                                    <x-icon name="pause" class="size-5" /> Pause
                                </button>
                                <button type="button" class="btn btn-secondary btn-lg" x-show="paused" x-cloak x-on:click="resume()">
                                    <x-icon name="play" class="size-5" /> Resume
                                </button>
                                <button type="button" class="btn btn-danger btn-lg" x-on:click="stop()">
                                    <x-icon name="square" class="size-5" /> Finish
                                </button>
                                <button type="button" class="btn btn-ghost btn-lg" x-on:click="compact = true" aria-label="Hide the recorder and keep only the notes">
                                    <x-icon name="minimize-2" class="size-5" /> Hide
                                </button>
                            </div>

                            {{-- Tap Note: that minute is where the note starts. Save note: that minute is where it ends. --}}
                            <div class="space-y-3 border-t border-divider pt-4">
                                <button type="button" class="btn btn-primary w-full justify-center py-4 text-base" x-show="!noting" x-on:click="startNote()">
                                    <x-icon name="notebook-pen" class="size-5" /> Note
                                </button>
                                <div class="space-y-2" x-show="noting" x-cloak>
                                    <label for="live-note-{{ $uid }}" class="field-label">Your note, from <span class="tabular-nums" x-text="stamp(noteFrom)"></span></label>
                                    <textarea id="live-note-{{ $uid }}" x-ref="note" x-model="noteText" class="input" rows="5" maxlength="8000" placeholder="What is being said, or what you want to remember"></textarea>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <button type="button" class="btn btn-primary" x-on:click="saveNote()" x-bind:disabled="noteBusy">Save note</button>
                                        <button type="button" class="btn btn-ghost" x-on:click="cancelNote()">Cancel</button>
                                    </div>
                                </div>
                                <ul class="space-y-2" x-show="kept.length > 0" x-cloak aria-label="Your notes so far">
                                    <template x-for="note in kept" :key="note.id">
                                        <li class="rounded-lg border border-border bg-surface-sunken p-3 text-sm">
                                            <span class="font-medium tabular-nums" x-text="stamp(note.from) + '–' + stamp(note.until)"></span>
                                            <span class="block whitespace-pre-line break-words" x-text="note.text"></span>
                                        </li>
                                    </template>
                                </ul>
                            </div>
                        </div>

                        {{-- Hidden: only the notes stay, and one quiet mic button brings the recorder back (to pause or finish). --}}
                        <button type="button" class="fixed bottom-4 right-4 flex size-12 items-center justify-center rounded-full border border-border bg-surface-sunken text-fg shadow-lg" x-show="compact" x-cloak x-on:click="compact = false" aria-label="Show the recorder">
                            <x-icon name="mic" class="size-5" />
                            <span class="absolute right-1 top-1 size-2.5 rounded-full" x-bind:class="paused ? 'bg-fg-muted' : 'bg-danger'" aria-hidden="true"></span>
                        </button>
                    </div>
                </template>
            </div>
        @endunless

        @if ($open !== [])
            {{-- Stacked, phone first: the words on top at full width, the buttons under them. --}}
            <ul class="space-y-3" role="list" aria-label="Unfinished recording">
                @foreach ($open as $part)
                    @php
                        $reached = $part->durationSeconds > 0 ? $part->durationSeconds : $part->chunks * \App\Study\ClassRecordings::PIECE_SECONDS;
                    @endphp
                    <li class="space-y-3 rounded-lg border border-border bg-surface-sunken p-4" wire:key="open-{{ $part->id }}">
                        <div class="flex items-start gap-3">
                            <span class="item-icon shrink-0" aria-hidden="true"><x-icon name="mic" class="size-5" /></span>
                            <div class="min-w-0 space-y-0.5">
                                <p class="font-medium">A recording that did not finish</p>
                                <p class="text-sm text-fg-muted">
                                    @if ($part->chunks > 0)
                                        About {{ ClassRecordingDetails::length($reached) }} was kept.
                                    @else
                                        Nothing was recorded.
                                    @endif
                                </p>
                            </div>
                        </div>
                        <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                            @if ($part->chunks > 0)
                                <x-button variant="primary" class="w-full justify-center sm:w-auto" wire:click="keepPart('{{ $part->id }}')" busy-label="Keeping…">Keep what was recorded</x-button>
                            @endif
                            <x-button variant="ghost" class="w-full justify-center sm:w-auto" wire:click="discard('{{ $part->id }}')" wire:confirm="Throw this recording away?">Discard</x-button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    @if ($tracks !== [])
        <div class="card space-y-4 p-5"
            x-data="classPlayer(@js($tracks))"
            x-on:class-seek.window="seek($event.detail)"
            x-on:class-use.window="use($event.detail)">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-medium">The recording</h2>
                <p class="text-sm tabular-nums text-fg-muted">Class time <span x-text="clock">0:00</span></p>
            </div>
            <audio x-ref="audio" controls preload="metadata" class="w-full" :src="current" x-on:timeupdate="tick()" x-on:ended="ended()"></audio>
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" class="btn btn-secondary btn-sm" x-on:click="skip(-15)" aria-label="Back 15 seconds">−15 s</button>
                <button type="button" class="btn btn-secondary btn-sm" x-on:click="skip(15)" aria-label="Forward 15 seconds">+15 s</button>
                <label for="speed-{{ $uid }}" class="sr-only">Speed</label>
                <select id="speed-{{ $uid }}" class="input w-auto py-1 text-sm" x-on:change="speed(Number($event.target.value))">
                    <option value="1">1×</option>
                    <option value="1.25">1.25×</option>
                    <option value="1.5">1.5×</option>
                    <option value="2">2×</option>
                    <option value="0.75">0.75×</option>
                </select>
            </div>
            @if (count($tracks) > 1)
                <p class="text-sm text-fg-muted">This class was kept in more than one take. It still plays as one recording, on one clock.</p>
            @endif
        </div>
    @endif

    @if ($tracks !== [])
        <div class="card space-y-3 p-5" data-test="transcript" @if ($transcribeJob) wire:poll.5s @endif>
            <h2 class="text-sm font-medium">Transcript and lecture notes</h2>
            @if ($transcribeJob)
                <p class="text-sm" role="status">Writing the class down… An hour of class takes a few minutes. You can leave this page; the notes appear in this module when they are ready.</p>
            @else
                @if ($class->lectureNotesId && $class->transcriptNoteId)
                    <div class="flex flex-wrap gap-2">
                        <a href="{{ route('workspaces.notes.show', [$workspaceId, $class->lectureNotesId]) }}" class="btn btn-primary btn-sm no-underline">Lecture notes</a>
                        <a href="{{ route('workspaces.notes.show', [$workspaceId, $class->transcriptNoteId]) }}" class="btn btn-secondary btn-sm no-underline">Full transcript</a>
                    </div>
                @endif
                <p class="text-sm text-fg-muted">The AI writes the class down word for word, then turns it into lecture notes with the times, so you can go back to the recording. What it can't make out is marked, never guessed. It uses your AI key.</p>
                @if ($transcribeProblem)
                    <x-alert tone="danger">{{ $transcribeProblem }}</x-alert>
                @endif
                <x-button size="sm" wire:click="transcribe" busy-label="Starting…">{{ $class->lectureNotesId ? 'Write it down again' : 'Write this class down' }}</x-button>
            @endif
        </div>
    @endif

    @if ($class->lectureNotesId)
        <div class="card space-y-3 p-5" data-test="class-check" @if ($checkJob) wire:poll.5s @endif>
            <h2 class="text-sm font-medium">Check against your files</h2>
            @if ($checkJob)
                <p class="text-sm" role="status">Comparing the class with your files… This takes a minute or two. You can leave this page; the check appears in this module when it is ready.</p>
            @else
                @if ($class->checkNoteId)
                    <a href="{{ route('workspaces.notes.show', [$workspaceId, $class->checkNoteId]) }}" class="btn btn-primary w-full justify-center no-underline sm:w-auto">Open the class check</a>
                @endif
                <p class="text-sm text-fg-muted">Tick the slides or handouts this lecture used. The AI sets what the teacher said beside what is in them: what was only said in class, what the class skipped, and where the two differ. It never decides who is right. It uses your AI key.</p>
                @if ($moduleFiles === [])
                    <p class="text-sm text-fg-muted">This module has no files yet. Add the slides in its Files tab, then come back.</p>
                @else
                    <fieldset class="space-y-1">
                        <legend class="sr-only">Files the lecture used</legend>
                        @foreach ($moduleFiles as $file)
                            <label class="flex min-h-[44px] items-center gap-3 rounded-lg px-2 text-sm hover:bg-surface-sunken" wire:key="check-file-{{ $file->id }}">
                                <input type="checkbox" value="{{ $file->id }}" wire:model="checkFiles">
                                <span class="min-w-0 break-words">{{ $file->fileName() }}</span>
                            </label>
                        @endforeach
                    </fieldset>
                    @if ($checkProblem)
                        <x-alert tone="danger">{{ $checkProblem }}</x-alert>
                    @endif
                    <x-button variant="secondary" class="w-full justify-center sm:w-auto" wire:click="check" busy-label="Starting…">{{ $class->checkNoteId ? 'Check again' : 'Check the class against these files' }}</x-button>
                @endif
            @endif
        </div>
    @endif

    @if ($class->durationSeconds > 0)
        <div class="card space-y-5 p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="max-w-xl space-y-1">
                    <h2 class="text-sm font-medium">The class note</h2>
                    <p class="text-sm text-fg-muted">One note for this class. A mark is a stretch of the recording, with your writing under it. Whatever else you write in the note itself stays as you left it.</p>
                </div>
                @if ($class->noteId)
                    <a href="{{ route('workspaces.notes.show', [$workspaceId, $class->noteId]) }}" class="btn btn-secondary btn-sm no-underline">
                        <x-icon name="notebook-pen" class="size-4" /> Open the note
                    </a>
                @endif
            </div>

            @error('mark') <p class="field-error" role="alert">{{ $message }}</p> @enderror

            @if ($marks !== [])
                <div class="space-y-2">
                    @foreach ($marks as $index => $mark)
                        <details class="rounded-lg border border-border bg-surface" wire:key="mark-{{ $index }}-{{ $mark['from'] }}-{{ $mark['until'] }}">
                            <summary class="flex cursor-pointer flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2">
                                <span class="text-sm font-medium tabular-nums">{{ $mark['from'] }}–{{ $mark['until'] }}</span>
                                <span class="min-w-0 flex-1 truncate text-sm">{{ $mark['about'] }}</span>
                            </summary>
                            <div class="space-y-3 border-t border-divider p-3">
                                <div class="flex flex-wrap items-center gap-2">
                                    @if ($tracks !== [])
                                        <button type="button" class="btn btn-secondary btn-sm" x-on:click="$dispatch('class-seek', '{{ $mark['from'] }}')">
                                            <x-icon name="play" class="size-4" /> Play this stretch
                                        </button>
                                    @endif
                                    <x-button variant="ghost" size="sm" wire:click="removeMark({{ $index }})" wire:confirm="Remove this mark and what you wrote under it?">Remove</x-button>
                                </div>
                                <div class="space-y-1">
                                    <label for="mark-about-{{ $uid }}-{{ $index }}" class="field-label">About</label>
                                    <input id="mark-about-{{ $uid }}-{{ $index }}" type="text" class="input" wire:model="marks.{{ $index }}.about" maxlength="120">
                                </div>
                                <div class="space-y-1">
                                    <label for="mark-body-{{ $uid }}-{{ $index }}" class="field-label">Note</label>
                                    <textarea id="mark-body-{{ $uid }}-{{ $index }}" class="input" rows="4" wire:model="marks.{{ $index }}.body" maxlength="8000"></textarea>
                                </div>
                            </div>
                        </details>
                    @endforeach
                </div>
                <x-button variant="secondary" wire:click="saveMarks" busy-label="Saving…">Save the note</x-button>
            @endif

            <details class="@if ($marks !== []) border-t border-divider pt-5 @endif">
            <summary class="cursor-pointer text-sm font-medium">Add a note by its time</summary>
            <form wire:submit="addMark" class="mt-3 space-y-3">
                <p class="text-sm text-fg-muted">While you record, the Note button is easier: it takes the minute for you. Here you choose the minutes yourself.</p>
                <p class="text-sm text-fg-muted">Play the recording to where the stretch begins, press <em>Use player time</em>, then do the same for where it ends. Or type a time like 10:00.</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="mark-from-{{ $uid }}" class="field-label">From</label>
                        <div class="flex items-center gap-2">
                            <input id="mark-from-{{ $uid }}" type="text" class="input" wire:model="from" maxlength="8" placeholder="10:00" inputmode="numeric" autocomplete="off">
                            @if ($tracks !== [])
                                <button type="button" class="btn btn-secondary btn-sm shrink-0" x-on:click="$dispatch('class-use', 'from')">Use player time</button>
                            @endif
                        </div>
                        @error('from') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="space-y-1">
                        <label for="mark-until-{{ $uid }}" class="field-label">Until</label>
                        <div class="flex items-center gap-2">
                            <input id="mark-until-{{ $uid }}" type="text" class="input" wire:model="until" maxlength="8" placeholder="20:00" inputmode="numeric" autocomplete="off">
                            @if ($tracks !== [])
                                <button type="button" class="btn btn-secondary btn-sm shrink-0" x-on:click="$dispatch('class-use', 'until')">Use player time</button>
                            @endif
                        </div>
                        @error('until') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="space-y-1">
                    <label for="mark-new-about-{{ $uid }}" class="field-label">About</label>
                    <input id="mark-new-about-{{ $uid }}" type="text" class="input" wire:model="about" maxlength="120" placeholder="Topic 2" autocomplete="off">
                    @error('about') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="space-y-1">
                    <label for="mark-new-body-{{ $uid }}" class="field-label">Note</label>
                    <textarea id="mark-new-body-{{ $uid }}" class="input" rows="3" wire:model="body" maxlength="8000" placeholder="What this stretch is about"></textarea>
                    @error('body') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <x-button type="submit" variant="primary" icon="bookmark" busy-label="Adding…">Add the note</x-button>
            </form>
            </details>
        </div>
    @endif

    @if ($class->durationSeconds > 0 || $open !== [])
        <div class="card space-y-2 p-5" data-test="delete">
            <h2 class="text-sm font-medium">Delete</h2>
            <p class="text-sm text-fg-muted">Finish recording first. Your notes, the transcript and the lecture notes are notes in this module: they stay unless you delete them there.</p>
            <div class="flex flex-wrap gap-2">
                <x-button variant="danger" size="sm" icon="trash-2" wire:click="deleteRecording" wire:confirm="Delete the recording of this class for good? The class and your notes stay.">Delete the recording</x-button>
                <x-button variant="ghost" size="sm" wire:click="deleteClass" wire:confirm="Delete this class and its recording for good? Your notes stay in the module.">Delete this class</x-button>
            </div>
        </div>
    @else
        <div class="flex justify-end">
            <x-button variant="ghost" size="sm" icon="trash-2" wire:click="deleteClass" wire:confirm="Delete this class?">Delete this class</x-button>
        </div>
    @endif
</div>
