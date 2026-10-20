{{--
    Ask (App\Livewire\Workspaces\Ask): the quick helper, in a sheet that the top bar's Ask button opens (the button is in
    layouts/app.blade.php). A short talk about the course that is not kept; a line at the bottom for when it is teaching that
    is wanted.
--}}
<div class="ask">
    <div role="status" aria-live="polite" class="empty:hidden"><x-toast :message="$notice" :tone="$noticeTone" /></div>

    <dialog id="ask-sheet" class="modal" aria-labelledby="ask-sheet-title" wire:ignore.self x-data
        x-on:click="$event.target === $el && $el.close()">
        <div class="modal-panel ask-panel"
            x-data="{
                fullscreen: false,
                take(event) {
                    const files = [...((event.clipboardData ?? event.dataTransfer)?.files ?? [])];
                    if (files.length > 0) {
                        event.preventDefault();
                        $wire.uploadMultiple('uploads', files);
                    }
                }
            }"
            x-bind:class="{ 'is-fullscreen': fullscreen }"
            x-on:paste="take($event)"
            x-on:drop.prevent="take($event)"
            x-on:dragover.prevent>
            <div class="modal-head ask-head">
                <div class="flex items-center gap-2.5 min-w-0 flex-1">
                    <span class="ws-chip size-7 rounded-lg shrink-0" aria-hidden="true">
                        <x-icon name="sparkles" class="size-4" />
                    </span>
                    <div class="min-w-0">
                        <h2 id="ask-sheet-title" class="text-base font-semibold leading-tight truncate">Ask</h2>
                        <p class="text-xs text-fg-muted truncate">Quick AI course assistant</p>
                    </div>
                </div>
                @if ($talk !== [] || $uploads !== [])
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="clear">Start over</button>
                @endif
                <button type="button" class="session-icon-btn" x-bind:class="{ 'is-active': fullscreen }" x-on:click="fullscreen = ! fullscreen" x-bind:title="fullscreen ? 'Exit full screen' : 'Full screen'" aria-label="Toggle full screen">
                    <x-icon name="maximize-2" class="size-3.5" x-show="! fullscreen" />
                    <x-icon name="minimize-2" class="size-3.5" x-show="fullscreen" x-cloak />
                </button>
                <button type="button" class="session-icon-btn" aria-label="Close" x-on:click="$el.closest('dialog').close()"><x-icon name="x" class="size-4" /></button>
            </div>

            <div class="ask-log px-4" role="log" aria-label="Talk with the helper" aria-live="polite" tabindex="0"
                x-ref="askLog"
                x-init="$watch('$wire.talk', () => $nextTick(() => { if ($refs.askLog) $refs.askLog.scrollTop = $refs.askLog.scrollHeight; }))">
                @if ($talk === [])
                    <div class="ask-empty-card">
                        <div class="ask-empty-icon" aria-hidden="true">
                            <x-icon name="sparkles" class="size-5" />
                        </div>
                        <p class="text-sm font-semibold text-fg">Ask anything about this course</p>
                        <p class="text-xs text-fg-muted leading-relaxed">Quick answers from your course materials and notes without changing your progress.</p>
                        <div class="ask-quick-chips" role="list" aria-label="Suggested questions">
                            <button type="button" class="study-map-chip" wire:click="quickAsk('What do I find hard in this course?')" wire:loading.attr="disabled">
                                <x-icon name="circle-alert" class="size-3.5 text-warning shrink-0" />
                                <span>What do I find hard?</span>
                            </button>
                            <button type="button" class="study-map-chip" wire:click="quickAsk('Which topics are not started yet?')" wire:loading.attr="disabled">
                                <x-icon name="circle-dot" class="size-3.5 text-accent shrink-0" />
                                <span>Topics not started</span>
                            </button>
                            <button type="button" class="study-map-chip" wire:click="quickAsk('What should I study next based on my progress?')" wire:loading.attr="disabled">
                                <x-icon name="sparkles" class="size-3.5 text-success shrink-0" />
                                <span>What to study next?</span>
                            </button>
                            <button type="button" class="study-map-chip" wire:click="quickAsk('What questions are still open in this course?')" wire:loading.attr="disabled">
                                <x-icon name="circle-help" class="size-3.5 text-info shrink-0" />
                                <span>Open questions</span>
                            </button>
                        </div>
                    </div>
                @endif
                @foreach ($talk as $i => $line)
                    <div @class(['ask-line', 'is-you' => $line['from'] === 'you', 'is-helper' => $line['from'] === 'helper', 'is-problem' => $line['from'] === 'problem']) wire:key="ask-{{ $i }}">
                        <span class="sr-only">{{ ['you' => 'You', 'helper' => 'Answer', 'problem' => 'Problem'][$line['from']] }}: </span>
                        @if (! empty($line['attachments']))
                            <div class="ask-line-attachments">
                                @foreach ($line['attachments'] as $att)
                                    @if ($att['kind'] === 'image' && ! empty($att['url']))
                                        <div class="ask-log-image-wrap">
                                            <img src="{{ $att['url'] }}" alt="{{ $att['name'] ?? 'Attached picture' }}" class="ask-log-image" />
                                        </div>
                                    @else
                                        <div class="chat-file">
                                            <x-icon name="paperclip" class="size-3.5 shrink-0" />
                                            <span class="truncate">{{ $att['name'] ?? 'Attached file' }}</span>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                        @if ($line['text'] !== '')
                            @if ($line['from'] === 'helper')
                                <div class="chat-markdown text-sm leading-relaxed break-words">{!! \App\Study\MarkdownPreview::html($line['text']) !!}</div>
                            @else
                                <p class="whitespace-pre-line break-words text-sm m-0">{{ $line['text'] }}</p>
                            @endif
                        @endif
                    </div>
                @endforeach
                <p class="ask-line is-thinking text-fg-muted text-xs" wire:loading wire:target="send,quickAsk" role="status"><span class="ai-dots" aria-hidden="true"><span></span><span></span><span></span></span> Looking…</p>
            </div>

            <div class="ask-dock">
            <form wire:submit="send" novalidate class="ask-form-wrap px-4"
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
                        const cursor = selected ? start + replacement.length : start + before.length;
                        el.setSelectionRange(cursor, cursor);
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
                        const allBulleted = lines.every(l => /^(\s*)[-*]\s+/.test(l));
                        let replacement;
                        if (allBulleted) {
                            replacement = lines.map(l => l.replace(/^(\s*)[-*]\s+/, '$1')).join('\n');
                        } else {
                            replacement = lines.map(l => (/^(\s*)[-*]\s+/.test(l) ? l : '- ' + l)).join('\n');
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
                                this.submit();
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
                                const [full, indent, marker, lineText] = listMatch;
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
                                this.submit();
                            }
                        }
                    },
                    submit() {
                        this.stopDictation(true);
                        this.$wire.send();
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
                x-init="
                    $nextTick(() => resize());
                    $watch('$wire.text', (val) => {
                        if (!val) {
                            $nextTick(() => {
                                if ($refs.box) {
                                    $refs.box.style.height = 'auto';
                                }
                            });
                        }
                    });
                ">
                <div class="ask-compose">
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

                    @if ($uploads !== [])
                        <ul class="chat-files ask-attachments" role="list" aria-label="Attached to your question">
                            @foreach ($uploads as $i => $file)
                                <li class="chat-file" wire:key="ask-upload-{{ $i }}">
                                    @if (method_exists($file, 'isPreviewable') && $file->isPreviewable())
                                        <img src="{{ $file->temporaryUrl() }}" alt="" class="ask-thumb">
                                    @else
                                        <x-icon name="paperclip" class="size-4 shrink-0" />
                                    @endif
                                    <span class="truncate">{{ $file->getClientOriginalName() }}</span>
                                    <button type="button" class="chat-file-remove" wire:click="removeUpload({{ $i }})" aria-label="Take {{ $file->getClientOriginalName() }} off">
                                        <x-icon name="x" class="size-3.5" />
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <div class="ask-box-row">
                        <label for="ask-text" class="sr-only">Your question</label>
                        <textarea
                            id="ask-text"
                            class="ask-box"
                            x-ref="box"
                            rows="1"
                            wire:model="text"
                            maxlength="{{ \App\Livewire\Workspaces\Ask::MAX_ASK }}"
                            placeholder="Ask about your course, picture, or file…"
                            wire:loading.attr="disabled"
                            wire:target="send"
                            x-on:input="resize()"
                            x-on:paste="$nextTick(() => resize())"
                            x-on:keydown="handleKeydown($event)"
                        ></textarea>
                    </div>

                    <div class="ask-compose-bar">
                        <div class="ask-compose-tools" role="toolbar" aria-label="Attachments and formatting">
                            <label class="ask-tool-btn" title="Attach picture or file" aria-label="Attach picture or file">
                                <x-icon name="paperclip" class="size-4" />
                                <input type="file" class="sr-only" wire:model="uploads" multiple accept="{{ \App\Study\FileTypes::accept() }}">
                            </label>
                            <button type="button" class="ask-tool-btn chat-mic-btn" :class="{ 'is-active': transcribing }" x-on:click="toggleDictation()" x-bind:title="transcribing ? 'Stop voice dictation' : 'Dictate with voice'" aria-label="Dictate with voice">
                                <x-icon name="mic" class="size-4" x-show="!transcribing" />
                                <x-icon name="mic-off" class="size-4" x-show="transcribing" x-cloak />
                            </button>
                            <button type="button" class="ask-tool-btn" :class="{ 'is-active': formatOpen }" @click="formatOpen = !formatOpen" title="Formatting tools" aria-label="Formatting tools" :aria-expanded="formatOpen.toString()">
                                <span class="font-semibold text-xs leading-none">Aa</span>
                            </button>

                            <div class="ask-format-group" x-show="formatOpen" x-cloak x-transition>
                                <span class="ask-tool-divider" aria-hidden="true"></span>
                                <button type="button" class="ask-tool-btn" x-on:click="wrap('**', '**', 'bold text')" title="Bold" aria-label="Bold">
                                    <x-icon name="bold" class="size-4" />
                                </button>
                                <button type="button" class="ask-tool-btn" x-on:click="wrap('*', '*', 'italic text')" title="Italic" aria-label="Italic">
                                    <x-icon name="italic" class="size-4" />
                                </button>
                                <button type="button" class="ask-tool-btn" x-on:click="wrap('`', '`', 'code')" title="Code in a line" aria-label="Code in a line">
                                    <x-icon name="code" class="size-4" />
                                </button>
                                <button type="button" class="ask-tool-btn" x-on:click="wrap('```\n', '\n```', 'code block')" title="Code block" aria-label="Code block">
                                    <x-icon name="square-code" class="size-4" />
                                </button>
                                <button type="button" class="ask-tool-btn" x-on:click="toggleList()" title="Bulleted list" aria-label="Bulleted list">
                                    <x-icon name="list" class="size-4" />
                                </button>
                                <button type="button" class="ask-tool-btn" x-on:click="wrap('$', '$', 'formula')" title="Formula" aria-label="Formula">
                                    <x-icon name="sigma" class="size-4" />
                                </button>
                            </div>
                        </div>

                        <div class="ask-compose-actions">
                            <span class="ask-shortcut-hint">Shift+Enter new line</span>
                            <button type="submit" class="btn btn-primary ask-send-btn" wire:loading.attr="aria-busy" wire:target="send" title="Ask" aria-label="Ask">
                                <span wire:loading.remove wire:target="send"><x-icon name="arrow-up" class="size-4" /></span>
                                <span wire:loading wire:target="send"><x-icon name="loader-circle" class="size-4 animate-spin" /></span>
                                <span class="sr-only">Ask</span>
                            </button>
                        </div>
                    </div>
                </div>

                <p class="text-xs text-fg-muted" wire:loading wire:target="uploads" role="status">
                    <x-icon name="loader-circle" class="size-3.5 animate-spin inline-block mr-1" />Getting the file…
                </p>
                @error('uploads') <p class="field-error">{{ $message }}</p> @enderror
                @error('uploads.*') <p class="field-error">{{ $message }}</p> @enderror
                @error('text') <p class="field-error">{{ $message }}</p> @enderror
            </form>

            <p class="ask-foot px-4 text-xs text-fg-muted">Need teaching? <button type="button" class="inline-link" wire:click="teach">Start a session</button>.</p>
            </div>
        </div>
    </dialog>
</div>
