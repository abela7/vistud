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
        x-data="{
            formatOpen: false,
            resize() {
                const el = this.$refs.box;
                if (!el) return;
                el.style.height = 'auto';
                el.style.height = Math.min(el.scrollHeight, 220) + 'px';
            },
            wrap(before, after = before, placeholder = '') {
                const el = this.$refs.box;
                if (!el) return;
                const start = el.selectionStart ?? 0;
                const end = el.selectionEnd ?? 0;
                const val = el.value || '';
                const selected = val.substring(start, end) || placeholder;
                const replacement = before + selected + after;
                el.value = val.substring(0, start) + replacement + val.substring(end);
                el.dispatchEvent(new Event('input', { bubbles: true }));
                el.focus();
                const newPos = selected ? start + replacement.length : start + before.length;
                el.setSelectionRange(newPos, newPos);
                this.$nextTick(() => this.resize());
            },
            toggleList() {
                const el = this.$refs.box;
                if (!el) return;
                const val = el.value || '';
                const start = el.selectionStart ?? 0;
                const end = el.selectionEnd ?? 0;
                const prevNewline = val.lastIndexOf('\n', start - 1);
                const lineStart = prevNewline === -1 ? 0 : prevNewline + 1;
                const nextNewline = val.indexOf('\n', end);
                const lineEnd = nextNewline === -1 ? val.length : nextNewline;
                const block = val.slice(lineStart, lineEnd);
                const lines = block.split('\n');
                const allBulleted = lines.every((l) => /^(\s*)[-*]\s+/.test(l));
                let replacement;
                if (allBulleted) {
                    replacement = lines.map((l) => l.replace(/^(\s*)[-*]\s+/, '$1')).join('\n');
                } else {
                    replacement = lines.map((l) => (/^(\s*)[-*]\s+/.test(l) ? l : '- ' + l)).join('\n');
                }
                el.value = val.slice(0, lineStart) + replacement + val.slice(lineEnd);
                el.dispatchEvent(new Event('input', { bubbles: true }));
                el.focus();
                const newPos = lineStart + replacement.length;
                el.setSelectionRange(newPos, newPos);
                this.$nextTick(() => this.resize());
            },
            handleKeydown(event) {
                const el = this.$refs.box;
                if (!el) return;
                if (event.ctrlKey || event.metaKey) {
                    if (event.key.toLowerCase() === 'b') {
                        event.preventDefault();
                        this.wrap('**', '**', 'bold text');
                        return;
                    }
                    if (event.key.toLowerCase() === 'i') {
                        event.preventDefault();
                        this.wrap('*', '*', 'italic text');
                        return;
                    }
                    if (event.key.toLowerCase() === 'e') {
                        event.preventDefault();
                        this.wrap('`', '`', 'code');
                        return;
                    }
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        $wire.send();
                        return;
                    }
                }
                if (event.key === 'Enter') {
                    const val = el.value || '';
                    const selStart = el.selectionStart ?? 0;
                    const selEnd = el.selectionEnd ?? 0;
                    const prevNewline = val.lastIndexOf('\n', selStart - 1);
                    const lineStart = prevNewline === -1 ? 0 : prevNewline + 1;
                    const nextNewline = val.indexOf('\n', selStart);
                    const lineEnd = nextNewline === -1 ? val.length : nextNewline;
                    const currentLine = val.slice(lineStart, lineEnd);
                    const listMatch = currentLine.match(/^(\s*)([-*]|\d+\.)\s*(.*)$/);
                    if (listMatch) {
                        const [, indent, marker, lineText] = listMatch;
                        if (lineText.trim() === '') {
                            event.preventDefault();
                            el.value = val.slice(0, lineStart) + val.slice(lineEnd);
                            el.setSelectionRange(lineStart, lineStart);
                            el.dispatchEvent(new Event('input', { bubbles: true }));
                            this.$nextTick(() => this.resize());
                            return;
                        }
                        if (event.shiftKey) {
                            event.preventDefault();
                            let nextMarker = marker;
                            if (/^\d+\.$/.test(marker)) {
                                nextMarker = (parseInt(marker, 10) + 1) + '.';
                            }
                            const continuation = '\n' + indent + nextMarker + ' ';
                            const before = val.slice(0, selStart);
                            const after = val.slice(selEnd);
                            el.value = before + continuation + after;
                            const newPos = selStart + continuation.length;
                            el.setSelectionRange(newPos, newPos);
                            el.dispatchEvent(new Event('input', { bubbles: true }));
                            this.$nextTick(() => this.resize());
                            return;
                        }
                    }
                    if (!event.shiftKey && !event.isComposing) {
                        event.preventDefault();
                        this.stopDictation(true);
                        $wire.send();
                    }
                }
            },
            take(event) {
                const files = [...((event.clipboardData ?? event.dataTransfer)?.files ?? [])].filter((file) => file.type.startsWith('image/'));
                if (files.length > 0) {
                    event.preventDefault();
                    $wire.uploadMultiple('pictures', files);
                }
            },
            transcribing: false,
            interimSpeech: '',
            recognition: null,
            startDictation() {
                const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
                if (!SpeechRecognition) {
                    window['alert']?.('Live speech transcription is not supported in this browser.');
                    return;
                }
                if (this.recognition) {
                    try { this.recognition.abort(); } catch {}
                    this.recognition = null;
                }
                try {
                    const recognition = new SpeechRecognition();
                    recognition.continuous = true;
                    recognition.interimResults = true;
                    recognition.maxAlternatives = 1;
                    recognition.lang = document.documentElement.lang || navigator.language || 'en-US';

                    recognition.onstart = () => {
                        this.transcribing = true;
                        this.interimSpeech = '';
                    };

                    recognition.onresult = (event) => {
                        let interim = '';
                        let finalizedChunk = '';

                        for (let i = event.resultIndex; i < event.results.length; ++i) {
                            const transcript = event.results[i][0]?.transcript || '';
                            if (event.results[i].isFinal) {
                                finalizedChunk += transcript;
                            } else {
                                interim += transcript;
                            }
                        }

                        if (finalizedChunk.trim()) {
                            const chunk = finalizedChunk.trim();
                            const current = (this.$wire.text || '').trim();
                            this.$wire.text = current ? `${current} ${chunk}` : chunk;
                            this.$nextTick(() => this.resize());
                        }

                        this.interimSpeech = interim.trim();
                    };

                    recognition.addEventListener('error', () => {
                        this.stopDictation(false);
                    });

                    recognition.onend = () => {
                        if (this.transcribing) {
                            this.commitInterim();
                            this.transcribing = false;
                        }
                        this.recognition = null;
                        this.$nextTick(() => {
                            this.resize();
                            this.$refs.box?.focus();
                        });
                    };

                    this.recognition = recognition;
                    recognition.start();
                } catch (err) {
                    this.transcribing = false;
                }
            },
            stopDictation(commit = true) {
                if (commit) {
                    this.commitInterim();
                } else {
                    this.interimSpeech = '';
                }
                this.transcribing = false;
                if (this.recognition) {
                    try { this.recognition.stop(); } catch {}
                    this.recognition = null;
                }
                this.$nextTick(() => {
                    this.resize();
                    this.$refs.box?.focus();
                });
            },
            cancelDictation() {
                this.stopDictation(false);
            },
            commitInterim() {
                if (this.interimSpeech && this.interimSpeech.trim()) {
                    const chunk = this.interimSpeech.trim();
                    const current = (this.$wire.text || '').trim();
                    this.$wire.text = current ? `${current} ${chunk}` : chunk;
                    this.interimSpeech = '';
                    this.$nextTick(() => this.resize());
                }
            },
            toggleDictation() {
                if (this.transcribing) {
                    this.stopDictation(true);
                } else {
                    this.startDictation();
                }
            }
        }"
        x-on:submit="stopDictation(true)"
        x-init="
            $nextTick(() => resize());
            $watch('$wire.text', (val) => {
                if (!val) {
                    $nextTick(() => {
                        if ($refs.box) $refs.box.style.height = 'auto';
                    });
                }
            });
        ">
        @if ($problem !== null && $errors->isEmpty())
            <x-alert tone="warning">{{ $problem }} <a href="{{ route('settings', ['part' => 'ai']) }}" class="text-link">AI settings</a></x-alert>
        @endif

        <div class="chat-compose">
            {{-- Live speech dictation status pill --}}
            <div class="chat-dictation-pill" x-show="transcribing" x-cloak role="status" aria-live="polite">
                <div class="chat-dictation-waves" aria-hidden="true">
                    <span class="chat-dictation-wave"></span>
                    <span class="chat-dictation-wave"></span>
                    <span class="chat-dictation-wave"></span>
                    <span class="chat-dictation-wave"></span>
                    <span class="chat-dictation-wave"></span>
                </div>
                <div class="chat-dictation-body">
                    <span class="chat-dictation-status">Listening…</span>
                    <span class="chat-dictation-preview" x-text="interimSpeech || 'Speak now…'"></span>
                </div>
                <div class="chat-dictation-actions">
                    <button type="button" class="chat-dictation-done-btn" x-on:click="stopDictation(true)" title="Done dictating" aria-label="Done dictating">
                        <x-icon name="check" class="size-3" />
                        <span>Done</span>
                    </button>
                    <button type="button" class="chat-dictation-cancel-btn" x-on:click="cancelDictation()" title="Cancel dictation" aria-label="Cancel dictation">
                        <x-icon name="x" class="size-3.5" />
                    </button>
                </div>
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

            <label for="guide-text" class="sr-only">Your message</label>
            <textarea id="guide-text" class="chat-box" rows="2" wire:model="text" maxlength="{{ \App\Engine\CourseGuide::MAX_MESSAGE }}"
                x-ref="box"
                placeholder="{{ $for === 'modules' ? 'Paste the timetable, tell me the weeks, or ask me to suggest some…' : 'Tell me about the course, or paste its page…' }}" wire:loading.attr="disabled" wire:target="send"
                x-on:paste="take($event); $nextTick(() => resize())" x-on:drop="take($event)" x-on:dragover.prevent
                x-on:input="resize()"
                x-on:keydown="handleKeydown($event)"></textarea>

            <div class="chat-compose-bar">
                <div class="chat-compose-tools" role="toolbar" aria-label="Attachments and formatting">
                    <label class="chat-tool-btn" title="Attach picture" aria-label="Attach picture">
                        <x-icon name="paperclip" class="size-4" />
                        <input type="file" class="sr-only" multiple accept="image/png,image/jpeg,image/webp,image/gif" wire:model="pictures">
                    </label>
                    <button type="button" class="chat-tool-btn chat-mic-btn" :class="{ 'is-active': transcribing }" x-on:click="toggleDictation()" x-bind:title="transcribing ? 'Stop voice dictation' : 'Dictate with voice'" aria-label="Dictate with voice">
                        <x-icon name="mic" class="size-4" x-show="!transcribing" />
                        <x-icon name="mic-off" class="size-4" x-show="transcribing" x-cloak />
                    </button>
                    <button type="button" class="chat-tool-btn" :class="{ 'is-active': formatOpen }" @click="formatOpen = !formatOpen" title="Formatting tools" aria-label="Formatting tools" :aria-expanded="formatOpen.toString()">
                        <span class="font-semibold text-xs leading-none">Aa</span>
                    </button>

                    <div class="chat-format-group" x-show="formatOpen" x-cloak x-transition>
                        <span class="chat-tool-divider" aria-hidden="true"></span>
                        <button type="button" class="chat-tool-btn" x-on:click="wrap('**', '**', 'bold text')" title="Bold" aria-label="Bold">
                            <x-icon name="bold" class="size-4" />
                        </button>
                        <button type="button" class="chat-tool-btn" x-on:click="wrap('*', '*', 'italic text')" title="Italic" aria-label="Italic">
                            <x-icon name="italic" class="size-4" />
                        </button>
                        <button type="button" class="chat-tool-btn" x-on:click="wrap('`', '`', 'code')" title="Code in a line" aria-label="Code in a line">
                            <x-icon name="code" class="size-4" />
                        </button>
                        <button type="button" class="chat-tool-btn" x-on:click="wrap('```\n', '\n```', 'code block')" title="Code block" aria-label="Code block">
                            <x-icon name="square-code" class="size-4" />
                        </button>
                        <button type="button" class="chat-tool-btn" x-on:click="toggleList()" title="Bulleted list" aria-label="Bulleted list">
                            <x-icon name="list" class="size-4" />
                        </button>
                        <button type="button" class="chat-tool-btn" x-on:click="wrap('$', '$', 'formula')" title="Formula" aria-label="Formula">
                            <x-icon name="sigma" class="size-4" />
                        </button>
                    </div>
                </div>

                <div class="chat-compose-actions">
                    <span class="chat-shortcut-hint">Shift+Enter new line</span>
                    <button type="submit" class="btn btn-primary chat-send" wire:loading.attr="aria-busy" wire:target="send" title="Send" aria-label="Send">
                        <span wire:loading.remove wire:target="send"><x-icon name="arrow-up" class="size-4" /></span>
                        <span wire:loading wire:target="send"><x-icon name="loader-circle" class="size-4 animate-spin" /></span>
                        <span class="sr-only">Send</span>
                    </button>
                </div>
            </div>
        </div>

        @error('text')
            <p class="field-error">{{ $message }}@if ($problem !== null) <a href="{{ route('settings', ['part' => 'ai']) }}" class="text-link no-underline">AI settings</a>@endif</p>
        @enderror
        <p class="guide-line text-fg-muted" wire:loading wire:target="pictures" role="status"><x-icon name="loader-circle" class="size-4 animate-spin" />Getting the picture…</p>
        @error('pictures')<p class="field-error">{{ $message }}</p>@enderror
        @error('pictures.*')<p class="field-error">{{ $message }}</p>@enderror

        <div class="guide-actions">
            <x-button type="submit" variant="primary" icon="arrow-right" wire:loading.attr="aria-busy" wire:target="send" busy-label="Reading…">Send</x-button>
            <label class="btn btn-secondary chat-upload no-underline">
                <x-icon name="paperclip" class="size-4" />Attach pictures
                <input type="file" class="sr-only" multiple accept="image/png,image/jpeg,image/webp,image/gif" wire:model="pictures">
            </label>
            @if ($toModules)
                <a href="{{ route('workspaces.show', [$workspace->id, 'modules']) }}" class="btn btn-secondary no-underline"><x-icon name="layers" class="size-4" />Go to Modules</a>
            @endif
            <a href="{{ route('workspaces.show', $workspace->id) }}" class="btn btn-secondary no-underline">I'm done for now</a>
        </div>
        <p class="text-sm text-fg-muted">Ctrl + Enter sends. You can paste or drop a screenshot of the course page{{ $for === 'modules' ? ' or timetable' : '' }} into the box. Nothing is added until you tick it and press Add.</p>
    </form>
</div>
