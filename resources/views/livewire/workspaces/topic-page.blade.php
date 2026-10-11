{{--
    Dedicated Topic Page (App\Livewire\Workspaces\TopicPage):
    Concise, calm, responsive 2-column layout matching ViStud theme rules:
    - Left column: AI Topic Tutor, Questions, Notes & Files, Key Points
    - Right sidebar: Where You Stand (Not started, Studying, Not understand, Understood), Progress stats, Topic settings
--}}
@php
    $shown = $topic->shown();
    $statusLabels = [
        'not_started' => ['label' => 'Not started', 'hint' => 'no contact yet'],
        'covered' => ['label' => 'Studying', 'hint' => 'Covered'],
        'confused' => ['label' => 'Not understand', 'hint' => 'Still confusing'],
        'understood' => ['label' => 'Understood', 'hint' => ''],
    ];
    $statusIcons = [
        'not_started' => 'circle',
        'covered' => 'book-open',
        'confused' => 'circle-alert',
        'understood' => 'circle-check',
    ];
    $statusColours = [
        'not_started' => 'purple',
        'covered' => 'blue',
        'confused' => 'amber',
        'understood' => 'green',
    ];

    $backUrl = match (true) {
        $folder !== null => route('workspaces.folders.show', [$workspaceId, $folder->id]),
        $module !== null => route('workspaces.modules.show', [$workspaceId, $module->id]),
        default => route('workspaces.show', [$workspaceId, 'modules']),
    };
    $backLabel = $folder ? $folder->name : ($module ? $module->title : 'Modules');

    $openQuestions = array_filter($questions, fn ($q) => $q->status !== 'answered');
    $answeredQuestions = array_filter($questions, fn ($q) => $q->status === 'answered');
@endphp

<div class="space-y-4">
    <x-toast :message="$notice" />

    {{-- Clean Standard ViStud Header --}}
    <x-workspace.section-header
        :workspace="$workspace"
        :title="$cleanName"
        :back-href="$backUrl"
        :back-to="$backLabel"
        :eyebrow="$workspace->name.($module ? ' · '.$module->title : '').($folder ? ' · '.$folder->name : '')">
        <x-ai-menu kind="topic" :id="$topic->id" :label="$cleanName" />
        <span @class(['status-chip', "status-{$shown}"])>
            <x-icon :name="$statusIcons[$shown] ?? 'circle-dot'" class="size-3.5" />
            <span>{{ $statusLabels[$shown]['label'] ?? ($shown === 'mastered' ? 'Mastered' : ucfirst($shown)) }}</span>
        </span>
    </x-workspace.section-header>

    {{-- Responsive 2-Column Layout --}}
    <div class="topic-page-layout">
        {{-- MAIN COLUMN: Deep-Dive Workspace --}}
        <div class="space-y-3 min-w-0">
            {{-- Modern Segmented Tab Strip --}}
            <nav class="page-tabs" aria-label="Topic navigation tabs">
                <button type="button"
                    wire:click="setTab('ai')"
                    @class(['page-tab', 'is-active' => $tab === 'ai'])
                    aria-current="{{ $tab === 'ai' ? 'page' : 'false' }}">
                    <x-icon name="sparkles" class="size-3.5" />
                    <span>AI Topic Tutor</span>
                </button>

                <button type="button"
                    wire:click="setTab('questions')"
                    @class(['page-tab', 'is-active' => $tab === 'questions'])
                    aria-current="{{ $tab === 'questions' ? 'page' : 'false' }}">
                    <x-icon name="circle-help" class="size-3.5" />
                    <span>Questions</span>
                    @if (count($openQuestions) > 0)
                        <span class="text-[10px] px-1.5 py-0.5 rounded-full bg-warning-subtle text-warning font-bold leading-none">{{ count($openQuestions) }}</span>
                    @endif
                </button>

                <button type="button"
                    wire:click="setTab('materials')"
                    @class(['page-tab', 'is-active' => $tab === 'materials'])
                    aria-current="{{ $tab === 'materials' ? 'page' : 'false' }}">
                    <x-icon name="folder" class="size-3.5" />
                    <span>Notes & Files</span>
                    <span class="text-[10px] text-fg-muted font-normal">({{ count($topicNotes) + count($topicFiles) }})</span>
                </button>

                <button type="button"
                    wire:click="setTab('settings')"
                    @class(['page-tab', 'is-active' => $tab === 'settings'])
                    aria-current="{{ $tab === 'settings' ? 'page' : 'false' }}">
                    <x-icon name="pin" class="size-3.5" />
                    <span>Key Points</span>
                    @if (count($points) > 0)
                        <span class="text-[10px] text-fg-muted font-normal">({{ count($points) }})</span>
                    @endif
                </button>
            </nav>

            {{-- TAB 1: DEDICATED AI TOPIC TUTOR --}}
            @if ($tab === 'ai')
                <div class="topic-panel p-0 overflow-hidden flex flex-col">
                    {{-- AI Header --}}
                    <div class="flex items-center justify-between gap-3 px-3.5 py-2.5 border-b border-border bg-surface-sunken">
                        <div class="flex items-center gap-2 min-w-0">
                            <x-icon name="sparkles" class="size-4 text-accent shrink-0" />
                            <h3 class="text-xs font-semibold text-fg truncate">AI Tutor · {{ $cleanName }}</h3>
                        </div>
                        <button type="button" class="btn btn-ghost text-xs text-fg-muted hover:text-fg py-1 px-2" wire:click="clearChat" title="Reset chat history">
                            Clear
                        </button>
                    </div>

                    {{-- Quick Prompt Chips --}}
                    <div class="study-map-chips p-1.5 border-b border-border/60 bg-surface-sunken flex-wrap gap-1" role="toolbar" aria-label="Topic quick ask chips">
                        <button type="button" class="study-map-chip text-[11px] py-0.5 px-2" wire:click="quickPrompt('Please explain the fundamental concepts of {{ addslashes($cleanName) }} in simple, plain terms with clear bullet points.')">
                            <x-icon name="sparkles" class="size-3 text-accent shrink-0" />
                            <span>Explain core concepts</span>
                        </button>
                        <button type="button" class="study-map-chip text-[11px] py-0.5 px-2" wire:click="quickPrompt('Give me 3 concrete, real-world practical examples and use cases of {{ addslashes($cleanName) }}.')">
                            <x-icon name="layers" class="size-3 text-warning shrink-0" />
                            <span>Real-world examples</span>
                        </button>
                        <button type="button" class="study-map-chip text-[11px] py-0.5 px-2" wire:click="quickPrompt('Quiz me with 3 challenging practice questions on {{ addslashes($cleanName) }} to test my understanding.')">
                            <x-icon name="circle-help" class="size-3 text-success shrink-0" />
                            <span>Test understanding</span>
                        </button>
                        <button type="button" class="study-map-chip text-[11px] py-0.5 px-2" wire:click="quickPrompt('What are the most common pitfalls, tricky edge cases, and exam tips for {{ addslashes($cleanName) }}?')">
                            <x-icon name="triangle-alert" class="size-3 text-danger shrink-0" />
                            <span>Common pitfalls</span>
                        </button>
                    </div>

                    {{-- Chat Stream --}}
                    <div class="study-map-chat-log p-3 space-y-2.5 max-h-[340px] overflow-y-auto" role="log" aria-label="Conversation with AI Topic Tutor"
                        x-data
                        x-ref="topicChatLog"
                        x-init="$watch('$wire.chat', () => $nextTick(() => { if ($refs.topicChatLog) $refs.topicChatLog.scrollTop = $refs.topicChatLog.scrollHeight; }))">
                        @if ($chat === [])
                            <div class="ask-empty-card my-1">
                                <div class="ask-empty-icon" aria-hidden="true">
                                    <x-icon name="sparkles" class="size-4" />
                                </div>
                                <p class="text-xs font-semibold text-fg">Dedicated AI Tutor for {{ $cleanName }}</p>
                                <p class="text-xs text-fg-muted leading-relaxed">Click any prompt chip above or ask a question below to get clear explanations, examples, or practice questions focused specifically on this topic.</p>
                            </div>
                        @endif
                        @foreach ($chat as $i => $msg)
                            <div class="study-map-chat-turn {{ $msg['role'] === 'user' ? 'is-user' : 'is-assistant' }}" wire:key="topic-chat-{{ $i }}">
                                <div class="study-map-chat-bubble text-xs">
                                    @if (! empty($msg['attachments']))
                                        <div class="ask-line-attachments mb-1.5">
                                            @foreach ($msg['attachments'] as $att)
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
                                    @if ($msg['role'] === 'assistant')
                                        <div class="chat-markdown max-w-none text-fg leading-relaxed">
                                            {!! $msg['html'] ?? nl2br(e($msg['text'])) !!}
                                        </div>
                                    @else
                                        <p class="whitespace-pre-line break-words m-0">{{ $msg['text'] }}</p>
                                    @endif
                                </div>
                                <span class="study-map-chat-time">{{ $msg['time'] ?? '' }}</span>
                            </div>
                        @endforeach

                        {{-- Thinking Loading State --}}
                        <div wire:loading wire:target="sendChat,quickPrompt" class="study-map-chat-thinking">
                            <span class="ai-dots" aria-hidden="true"><span></span><span></span><span></span></span>
                            <span class="text-xs">AI Tutor is preparing explanation…</span>
                        </div>
                    </div>

                    {{-- Standard Robust Compose Interface --}}
                    <div class="p-2.5 border-t border-border bg-surface-raised">
                        <form wire:submit.prevent="sendChat" novalidate class="w-full"
                            x-data="{
                                formatOpen: false,
                                resize() {
                                    const el = this.$refs.box;
                                    if (!el) return;
                                    el.style.height = 'auto';
                                    el.style.height = Math.min(el.scrollHeight, 160) + 'px';
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
                                            this.stopDictation(true);
                                            $wire.sendChat();
                                            return;
                                        }
                                    }
                                    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
                                        event.preventDefault();
                                        this.stopDictation(true);
                                        $wire.sendChat();
                                    }
                                },
                                take(event) {
                                    const files = [...((event.clipboardData ?? event.dataTransfer)?.files ?? [])];
                                    if (files.length > 0) {
                                        event.preventDefault();
                                        $wire.uploadMultiple('chatUploads', files);
                                    }
                                },
                                transcribing: false,
                                interimSpeech: '',
                                recognition: null,
                                startDictation() {
                                    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
                                    if (!SpeechRecognition) {
                                        alert('Live speech transcription is not supported in this browser.');
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
                                                const current = (this.$wire.chatText || '').trim();
                                                this.$wire.chatText = current ? `${current} ${chunk}` : chunk;
                                                this.$nextTick(() => this.resize());
                                            }

                                            this.interimSpeech = interim.trim();
                                        };

                                        recognition.onerror = () => {
                                            this.stopDictation(false);
                                        };

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
                                        const current = (this.$wire.chatText || '').trim();
                                        this.$wire.chatText = current ? `${current} ${chunk}` : chunk;
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
                                $watch('$wire.chatText', (val) => {
                                    if (!val) {
                                        $nextTick(() => {
                                            if ($refs.box) $refs.box.style.height = 'auto';
                                        });
                                    }
                                });
                            ">
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

                                {{-- Uploaded / Pasted Attachments --}}
                                @if ($chatUploads !== [])
                                    <ul class="chat-files ask-attachments mb-1.5" role="list" aria-label="Attached to your question">
                                        @foreach ($chatUploads as $i => $file)
                                            <li class="chat-file" wire:key="topic-chat-upload-{{ $i }}">
                                                @if (method_exists($file, 'isPreviewable') && $file->isPreviewable())
                                                    <img src="{{ $file->temporaryUrl() }}" alt="" class="ask-thumb">
                                                @else
                                                    <x-icon name="paperclip" class="size-3.5 shrink-0" />
                                                @endif
                                                <span class="truncate">{{ $file->getClientOriginalName() }}</span>
                                                <button type="button" class="chat-file-remove" wire:click="removeChatUpload({{ $i }})" aria-label="Remove {{ $file->getClientOriginalName() }}">
                                                    <x-icon name="x" class="size-3" />
                                                </button>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif

                                <label for="topic-chat-input" class="sr-only">Ask the AI Topic Tutor</label>
                                <textarea id="topic-chat-input" class="chat-box text-xs" rows="2"
                                    wire:model="chatText"
                                    x-ref="box"
                                    placeholder="Ask anything about {{ $cleanName }}…"
                                    wire:loading.attr="disabled" wire:target="sendChat"
                                    x-on:paste="take($event); $nextTick(() => resize())"
                                    x-on:drop.prevent="take($event)"
                                    x-on:dragover.prevent
                                    x-on:input="resize()"
                                    x-on:keydown="handleKeydown($event)"></textarea>

                                <div class="chat-compose-bar">
                                    <div class="chat-compose-tools" role="toolbar" aria-label="Attachments and formatting">
                                        <label class="chat-tool-btn cursor-pointer" title="Attach picture or file" aria-label="Attach picture or file">
                                            <x-icon name="paperclip" class="size-3.5" />
                                            <input type="file" class="sr-only" wire:model="chatUploads" multiple accept="{{ \App\Study\FileTypes::accept() }}">
                                        </label>
                                        <button type="button" class="chat-tool-btn chat-mic-btn" :class="{ 'is-active': transcribing }" x-on:click="toggleDictation()" x-bind:title="transcribing ? 'Stop voice dictation' : 'Dictate with voice'" aria-label="Dictate with voice">
                                            <x-icon name="mic" class="size-3.5" x-show="!transcribing" />
                                            <x-icon name="mic-off" class="size-3.5" x-show="transcribing" x-cloak />
                                        </button>
                                        <button type="button" class="chat-tool-btn" :class="{ 'is-active': formatOpen }" @click="formatOpen = !formatOpen" title="Formatting tools" aria-label="Formatting tools" :aria-expanded="formatOpen.toString()">
                                            <span class="font-semibold text-xs leading-none">Aa</span>
                                        </button>

                                        <div class="chat-format-group" x-show="formatOpen" x-cloak x-transition>
                                            <span class="chat-tool-divider" aria-hidden="true"></span>
                                            <button type="button" class="chat-tool-btn" x-on:click="wrap('**', '**', 'bold text')" title="Bold" aria-label="Bold">
                                                <x-icon name="bold" class="size-3.5" />
                                            </button>
                                            <button type="button" class="chat-tool-btn" x-on:click="wrap('*', '*', 'italic text')" title="Italic" aria-label="Italic">
                                                <x-icon name="italic" class="size-3.5" />
                                            </button>
                                            <button type="button" class="chat-tool-btn" x-on:click="wrap('`', '`', 'code')" title="Code inline" aria-label="Code inline">
                                                <x-icon name="code" class="size-3.5" />
                                            </button>
                                            <button type="button" class="chat-tool-btn" x-on:click="wrap('```\n', '\n```', 'code block')" title="Code block" aria-label="Code block">
                                                <x-icon name="square-code" class="size-3.5" />
                                            </button>
                                            <button type="button" class="chat-tool-btn" x-on:click="toggleList()" title="Bulleted list" aria-label="Bulleted list">
                                                <x-icon name="list" class="size-3.5" />
                                            </button>
                                            <button type="button" class="chat-tool-btn" x-on:click="wrap('$', '$', 'formula')" title="Formula" aria-label="Formula">
                                                <x-icon name="sigma" class="size-3.5" />
                                            </button>
                                        </div>
                                    </div>

                                    <div class="chat-compose-actions">
                                        <span class="chat-shortcut-hint">Shift+Enter new line</span>
                                        <button type="submit" class="btn btn-primary chat-send" wire:loading.attr="aria-busy" wire:target="sendChat" title="Send question to AI" aria-label="Send question to AI">
                                            <span wire:loading.remove wire:target="sendChat"><x-icon name="arrow-up" class="size-3.5" /></span>
                                            <span wire:loading wire:target="sendChat"><x-icon name="loader-circle" class="size-3.5 animate-spin" /></span>
                                            <span class="sr-only">Send</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            @endif

            {{-- TAB 2: TOPIC SPECIFIC QUESTIONS --}}
            @if ($tab === 'questions')
                <div class="space-y-3">
                    {{-- Add Question --}}
                    <div class="topic-panel space-y-2">
                        <h3 class="field-label flex items-center gap-1.5">
                            <x-icon name="circle-help" class="size-3.5 text-accent" />
                            <span>Ask a question on this topic</span>
                        </h3>
                        <form wire:submit="askQuestion" novalidate class="flex items-center gap-2">
                            <div class="min-w-0 flex-1">
                                <label for="topic-new-question" class="sr-only">Your Question</label>
                                <input id="topic-new-question" class="input w-full text-xs py-1.5" type="text"
                                    wire:model="newQuestionText"
                                    placeholder="What don't you understand about {{ $cleanName }} yet?"
                                    maxlength="1000" autocomplete="off" />
                                @error('newQuestionText') <p class="field-error mt-1 text-[11px]">{{ $message }}</p> @enderror
                            </div>
                            <x-button size="sm" type="submit" variant="primary" icon="plus" wire:loading.attr="aria-busy" wire:target="askQuestion" busy-label="Saving…">
                                Add
                            </x-button>
                        </form>
                    </div>

                    {{-- Questions List --}}
                    <div class="topic-panel space-y-3">
                        <div class="flex items-center justify-between gap-2 border-b border-border/60 pb-2">
                            <h3 class="text-xs font-semibold text-fg">Questions ({{ count($questions) }})</h3>
                            <div class="flex items-center gap-1.5 text-[11px]">
                                <span class="px-1.5 py-0.5 rounded-full bg-warning-subtle text-warning font-semibold">{{ count($openQuestions) }} Open</span>
                                <span class="px-1.5 py-0.5 rounded-full bg-success-subtle text-success font-semibold">{{ count($answeredQuestions) }} Understood</span>
                            </div>
                        </div>

                        @if ($questions === [])
                            <div class="text-center py-3 text-fg-muted">
                                <p class="text-xs font-medium">No questions asked yet.</p>
                                <p class="text-[11px] mt-0.5">Whenever you get stuck or confused, add a question above.</p>
                            </div>
                        @else
                            <ul class="divide-y divide-border/50 text-xs" role="list">
                                @foreach ($questions as $q)
                                    <li class="py-2.5 space-y-1.5" wire:key="question-item-{{ $q->id }}">
                                        <div class="flex items-start justify-between gap-2">
                                            <div class="min-w-0 flex-1 space-y-1">
                                                <div class="flex items-center gap-1.5">
                                                    <span @class([
                                                        'inline-flex items-center text-[10px] px-1.5 py-0.2 rounded font-semibold',
                                                        'bg-warning-subtle text-warning' => $q->status === 'stuck',
                                                        'bg-info-subtle text-info' => $q->status === 'pending',
                                                        'bg-success-subtle text-success' => $q->status === 'answered',
                                                    ])>
                                                        {{ ucfirst($q->status) }}
                                                    </span>
                                                    <span class="text-[11px] text-fg-muted">{{ \Illuminate\Support\Carbon::parse($q->askedAt)->format('j M') }}</span>
                                                </div>
                                                <a href="{{ route('workspaces.questions.show', [$workspaceId, $q->id]) }}" class="font-medium text-fg hover:text-accent transition-colors block break-words" style="text-decoration: none !important;">
                                                    {{ $q->text }}
                                                </a>
                                                @if ($q->answer)
                                                    <p class="text-[11px] text-fg-muted bg-surface-sunken p-2 rounded border border-border/50 break-words">
                                                        <strong class="text-fg">Answer:</strong> {{ $q->answer }}
                                                    </p>
                                                @endif
                                            </div>

                                            <div class="flex items-center gap-1 shrink-0">
                                                @if ($q->status !== 'answered')
                                                    <button type="button" class="btn btn-secondary text-xs py-1 px-2" wire:click="startAnswering('{{ $q->id }}')">
                                                        Answer
                                                    </button>
                                                    @if ($q->status !== 'stuck')
                                                        <button type="button" class="btn btn-ghost text-xs text-warning py-1 px-2" wire:click="markQuestionStuck('{{ $q->id }}')">
                                                            Stuck
                                                        </button>
                                                    @endif
                                                @endif
                                            </div>
                                        </div>

                                        {{-- Inline Answer Form --}}
                                        @if ($answeringQuestionId === $q->id)
                                            <div class="p-2.5 rounded-lg border border-accent bg-accent-subtle space-y-2 mt-1">
                                                <label for="answer-input-{{ $q->id }}" class="block text-[11px] font-semibold text-fg">Write answer / solution:</label>
                                                <textarea id="answer-input-{{ $q->id }}" class="input w-full text-xs" rows="2" wire:model="answeringText" placeholder="What explains this question?"></textarea>
                                                <div class="flex items-center justify-end gap-1.5">
                                                    <x-button size="xs" variant="ghost" wire:click="cancelAnswering">Cancel</x-button>
                                                    <x-button size="xs" variant="primary" wire:click="saveAnswer('{{ $q->id }}')">Save Answer</x-button>
                                                </div>
                                            </div>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            @endif

            {{-- TAB 3: NOTES & FILES ON THIS TOPIC --}}
            @if ($tab === 'materials')
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    {{-- Notes --}}
                    <div class="topic-panel space-y-2.5">
                        <div class="flex items-center justify-between gap-2 border-b border-border/60 pb-2">
                            <h3 class="text-xs font-semibold text-fg flex items-center gap-1.5">
                                <x-icon name="file-text" class="size-3.5 text-accent" />
                                <span>Notes ({{ count($topicNotes) }})</span>
                            </h3>
                            @php
                                $noteTarget = $topic->folderId ? "folder:{$topic->folderId}" : ($topic->moduleId ? "module:{$topic->moduleId}" : '');
                                $newNoteUrl = route('workspaces.notes.create', array_filter([
                                    'workspace' => $workspaceId,
                                    'in' => $noteTarget ?: null,
                                    'title' => "Notes on {$cleanName}",
                                ]));
                            @endphp
                            <a href="{{ $newNoteUrl }}" class="btn btn-secondary text-xs py-1 px-2 inline-flex items-center gap-1" style="text-decoration: none !important;">
                                <x-icon name="plus" class="size-3" />
                                <span>New Note</span>
                            </a>
                        </div>

                        @if ($topicNotes === [])
                            <div class="text-center py-3 text-fg-muted">
                                <p class="text-xs font-medium">No notes created yet.</p>
                                <p class="text-[11px] mt-0.5">Write notes or synthesize lecture takeaways.</p>
                            </div>
                        @else
                            <ul class="divide-y divide-border/50 text-xs" role="list">
                                @foreach ($topicNotes as $n)
                                    <li class="py-2 flex items-center justify-between gap-2" wire:key="topic-note-{{ $n->id }}">
                                        <div class="min-w-0 flex-1">
                                            <a href="{{ route('workspaces.notes.show', [$workspaceId, $n->id]) }}" class="font-medium text-fg hover:text-accent transition-colors truncate block" style="text-decoration: none !important;">
                                                {{ $n->displayTitle() }}
                                            </a>
                                            <span class="text-[11px] text-fg-muted">{{ \Illuminate\Support\Carbon::parse($n->updatedAt)->diffForHumans() }}</span>
                                        </div>
                                        <a href="{{ route('workspaces.notes.show', [$workspaceId, $n->id]) }}" class="btn btn-ghost text-xs py-1 px-2 shrink-0" style="text-decoration: none !important;">
                                            Open
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    {{-- Files --}}
                    <div class="topic-panel space-y-2.5">
                        <div class="flex items-center justify-between gap-2 border-b border-border/60 pb-2">
                            <h3 class="text-xs font-semibold text-fg flex items-center gap-1.5">
                                <x-icon name="paperclip" class="size-3.5 text-success" />
                                <span>Files ({{ count($topicFiles) }})</span>
                            </h3>
                        </div>

                        {{-- Upload File --}}
                        <div class="p-2 rounded-lg border border-dashed border-border/80 bg-surface-sunken space-y-1">
                            <label for="topic-file-upload" class="block text-[11px] font-semibold text-fg">Upload document or slide:</label>
                            <div class="flex items-center gap-2">
                                <input id="topic-file-upload" type="file" wire:model="uploadedFile" class="text-xs file:mr-2 file:py-1 file:px-2 file:rounded file:border-0 file:text-xs file:font-semibold file:bg-accent file:text-on-accent hover:file:bg-accent-hover cursor-pointer" />
                                @if ($uploadedFile)
                                    <x-button size="xs" variant="primary" wire:click="uploadFileToTopic" wire:loading.attr="aria-busy" wire:target="uploadFileToTopic">Save File</x-button>
                                @endif
                            </div>
                            @error('uploadedFile') <p class="field-error text-[11px] mt-1">{{ $message }}</p> @enderror
                        </div>

                        @if ($topicFiles === [])
                            <div class="text-center py-3 text-fg-muted">
                                <p class="text-xs font-medium">No files attached yet.</p>
                                <p class="text-[11px] mt-0.5">Upload lecture slides, readings, or labs.</p>
                            </div>
                        @else
                            <ul class="divide-y divide-border/50 text-xs" role="list">
                                @foreach ($topicFiles as $f)
                                    <li class="py-2 flex items-center justify-between gap-2" wire:key="topic-file-{{ $f->id }}">
                                        <div class="min-w-0 flex-1">
                                            <a href="{{ route('workspaces.files.show', [$workspaceId, $f->id]) }}" class="font-medium text-fg hover:text-accent transition-colors truncate block" style="text-decoration: none !important;">
                                                {{ $f->fileName() }}
                                            </a>
                                            <span class="text-[11px] text-fg-muted">{{ \Illuminate\Support\Number::fileSize($f->size) }}</span>
                                        </div>
                                        <a href="{{ route('workspaces.files.show', [$workspaceId, $f->id]) }}" class="btn btn-ghost text-xs py-1 px-2 shrink-0" style="text-decoration: none !important;">
                                            View
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            @endif

            {{-- TAB 4: KEY POINTS --}}
            @if ($tab === 'settings')
                <div class="topic-panel space-y-2.5">
                    <div class="border-b border-border/60 pb-2">
                        <h3 class="text-xs font-semibold text-fg flex items-center gap-1.5">
                            <x-icon name="pin" class="size-3.5 text-accent" />
                            <span>Key Points ({{ count($points) }})</span>
                        </h3>
                        <p class="text-[11px] text-fg-muted mt-0.5">Essential takeaways and formulas pinned to this topic.</p>
                    </div>

                    @if ($points !== [])
                        <ul class="divide-y divide-border/50 text-xs" role="list">
                            @foreach ($points as $kp)
                                <li class="py-1.5 flex items-start justify-between gap-2" wire:key="setting-point-{{ $kp->id }}">
                                    <div class="min-w-0 flex-1 text-fg break-words">
                                        <span>{{ $kp->text }}</span>
                                        @if ($kp->sourceName !== null)
                                            <span class="text-[11px] text-fg-muted block mt-0.5">from {{ $kp->sourceName }}{{ $kp->locator ? ', '.$kp->locator : '' }}</span>
                                        @endif
                                    </div>
                                    <button type="button" class="topbar-button -my-1 shrink-0 text-fg-muted hover:text-danger" wire:click="removePoint('{{ $kp->id }}')" aria-label="Remove key point">
                                        <x-icon name="trash-2" class="size-3.5" />
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <div class="text-center py-3 text-fg-muted">
                            <p class="text-xs">No key points pinned yet.</p>
                        </div>
                    @endif

                    <form wire:submit="addPoint" novalidate class="flex items-center gap-2 pt-1 border-t border-border/50">
                        <div class="min-w-0 flex-1">
                            <label for="topic-new-point" class="sr-only">Add key point</label>
                            <input id="topic-new-point" class="input w-full text-xs py-1.5" type="text" wire:model="newPoint" placeholder="Add a key point or takeaway…" maxlength="500" autocomplete="off" />
                            @error('newPoint') <p class="field-error mt-1 text-[11px]">{{ $message }}</p> @enderror
                        </div>
                        <x-button size="sm" type="submit" variant="primary" icon="plus" wire:loading.attr="aria-busy" wire:target="addPoint">Add Point</x-button>
                    </form>
                </div>
            @endif
        </div>

        {{-- SIDEBAR: Where You Stand & Progress & Settings --}}
        <aside class="space-y-2.5" aria-label="Topic options and standing">
            {{-- Panel 1: Where You Stand + Compact 2x2 Progress Grid --}}
            <section class="topic-panel space-y-2.5" aria-labelledby="topic-standing-title">
                <h2 id="topic-standing-title" class="field-label flex items-center justify-between m-0">
                    <span>Where You Stand</span>
                    <span class="text-[11px] font-normal text-fg-muted">{{ $topic->evidence() }}</span>
                </h2>

                <div class="grid grid-cols-2 gap-1.5" role="radiogroup" aria-labelledby="topic-standing-title">
                    @foreach ($statusLabels as $value => $meta)
                        @php
                            $isSelected = ($value === 'not_started' && ($topic->status === null || $topic->status === 'not_started')) || $topic->status === $value;
                            $isConfused = $value === 'confused';
                        @endphp
                        <button type="button"
                            wire:click="report('{{ $value }}')"
                            @class([
                                'topic-choice status-choice',
                                'ws-colour-'.$statusColours[$value],
                                'is-active' => $isSelected,
                                'is-confused' => $isConfused,
                            ])
                            aria-pressed="{{ $isSelected ? 'true' : 'false' }}">
                            <x-icon :name="$statusIcons[$value]" class="size-3.5 shrink-0" />
                            <span class="truncate font-semibold">{{ $meta['label'] }}@if ($meta['hint'] !== '') <span class="sr-only">({{ $meta['hint'] }})</span>@endif</span>
                            @if ($isSelected)
                                <span class="topic-choice-check" title="Current status">
                                    <x-icon name="check" class="size-2 text-surface-raised" />
                                </span>
                            @endif
                        </button>
                    @endforeach
                </div>

                @if ($topic->byTutor())
                    <p class="text-[11px] text-fg-muted mt-1 leading-tight">
                        Set by the tutor{{ $topic->statusAt !== null ? ', '.\Illuminate\Support\Carbon::parse($topic->statusAt, 'UTC')->format('j M') : '' }}. Pick another to change it.
                    </p>
                @endif

                {{-- Compact 2x2 Progress Stats inside the same card --}}
                <div class="pt-2 border-t border-border/50" aria-labelledby="topic-progress-title">
                    <h3 id="topic-progress-title" class="sr-only">Progress</h3>
                    <dl class="grid grid-cols-2 gap-1.5 text-[11px]">
                        <div class="p-1.5 rounded bg-surface-sunken border border-border/50 flex items-center justify-between gap-1">
                            <dt class="text-fg-muted">Cards</dt>
                            <dd class="font-semibold text-fg">
                                @if ($cards['total'] > 0)
                                    <a href="{{ route('workspaces.flashcards.review', [$workspaceId, 'topic' => $topic->id]) }}" class="text-accent" style="text-decoration: none !important;">
                                        {{ $cards['total'] }}{{ $cards['due'] > 0 ? ' ('.$cards['due'].')' : '' }}
                                    </a>
                                @else
                                    <span>0</span>
                                @endif
                            </dd>
                        </div>
                        <div class="p-1.5 rounded bg-surface-sunken border border-border/50 flex items-center justify-between gap-1">
                            <dt class="text-fg-muted">Questions</dt>
                            <dd class="font-semibold text-fg">{{ count($openQuestions) }} / {{ count($questions) }}</dd>
                        </div>
                        <div class="p-1.5 rounded bg-surface-sunken border border-border/50 flex items-center justify-between gap-1">
                            <dt class="text-fg-muted">Key Points</dt>
                            <dd class="font-semibold text-fg">{{ count($points) }}</dd>
                        </div>
                        <div class="p-1.5 rounded bg-surface-sunken border border-border/50 flex items-center justify-between gap-1">
                            <dt class="text-fg-muted">Sessions</dt>
                            <dd class="font-semibold text-fg">{{ count($sessions) }}</dd>
                        </div>
                    </dl>
                </div>
            </section>

            {{-- Panel 3: Manage Topic (Rename, Move, Delete) --}}
            <section class="topic-panel space-y-3" aria-labelledby="topic-manage-title">
                <h2 id="topic-manage-title" class="field-label m-0">Manage topic</h2>

                {{-- Rename --}}
                <form wire:submit="rename" novalidate class="flex items-end gap-1.5">
                    <div class="min-w-0 flex-1">
                        <label for="topic-rename-input" class="sr-only">Rename Topic</label>
                        <input id="topic-rename-input" class="input text-xs w-full py-1.5" type="text" wire:model="name" maxlength="120" placeholder="Topic name" autocomplete="off" />
                        @error('name') <p class="field-error mt-1 text-[11px]">{{ $message }}</p> @enderror
                    </div>
                    <x-button size="sm" type="submit" wire:loading.attr="aria-busy" wire:target="rename">Rename</x-button>
                </form>

                {{-- Move --}}
                <div class="flex items-end gap-1.5 pt-1.5 border-t border-border/50">
                    <div class="min-w-0 flex-1">
                        <label for="topic-page-place" class="sr-only">Module or folder</label>
                        <select id="topic-page-place" class="input text-xs w-full py-1.5" wire:model="place">
                            <option value="">No module</option>
                            @foreach ($modules as $mod)
                                @if (($foldersByModule[$mod->id] ?? []) === [])
                                    <option value="module:{{ $mod->id }}">{{ $mod->title }}</option>
                                @else
                                    <optgroup label="{{ $mod->title }}">
                                        <option value="module:{{ $mod->id }}">In {{ $mod->title }}</option>
                                        @foreach ($foldersByModule[$mod->id] as $fld)
                                            <option value="folder:{{ $fld['id'] }}">{{ $fld['name'] }}</option>
                                        @endforeach
                                    </optgroup>
                                @endif
                            @endforeach
                        </select>
                        @error('place') <p class="field-error mt-1 text-[11px]">{{ $message }}</p> @enderror
                    </div>
                    <x-button size="sm" wire:click="move" wire:loading.attr="aria-busy" wire:target="move">Move</x-button>
                </div>

                {{-- Danger: Remove --}}
                <div class="pt-1.5 border-t border-border/50">
                    @if ($confirmingRemove)
                        <div class="p-2.5 bg-danger-subtle rounded-lg border border-danger space-y-2">
                            <p class="text-xs text-danger font-medium">Remove {{ $cleanName }}?</p>
                            <div class="flex items-center gap-1.5">
                                <x-button size="xs" variant="ghost" wire:click="cancelRemove">Keep it</x-button>
                                <x-button size="xs" variant="danger" icon="trash-2" wire:click="removeTopic" wire:loading.attr="aria-busy" wire:target="removeTopic" busy-label="Removing…">Confirm</x-button>
                            </div>
                        </div>
                    @else
                        <button type="button" class="btn btn-ghost text-xs text-danger hover:opacity-80 w-full justify-center py-1" wire:click="askRemove">
                            <x-icon name="trash-2" class="size-3.5" />
                            <span>Remove Topic</span>
                        </button>
                    @endif
                </div>
            </section>
        </aside>
    </div>
</div>
