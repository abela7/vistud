{{--
    Session Study Map & Learning Roadmap (docs/specs/vistud-2-blueprint.md §3.5.3)
    Synthesizes session materials (files & notes) into a concise study roadmap with 3–5 trackable topics.
    Features:
    - Vertical List Mode with interactive accordion details
    - Real-time AI Chat Assistant to elaborate, adjust, rename, split, or refine topics
    - Inline topic renaming and single/batch topic acceptance
    - Zero underlines across all links, buttons, and chips
--}}
@php
    $suggByName = [];
    foreach ($suggestions as $s) {
        $suggByName[mb_strtolower(trim($s->name))] = $s;
    }
    $topicByName = [];
    foreach ($placeTopics as $t) {
        $topicByName[mb_strtolower(trim($t->name))] = $t;
    }
@endphp

<div class="session-study-map" aria-label="Session study map" x-data="{ openAccordion: 'stage-1' }">
    {{-- Header --}}
    <div class="study-map-head">
        <div class="flex items-center gap-2.5 min-w-0">
            <span class="session-kpi-icon ws-colour-purple shrink-0" aria-hidden="true">
                <x-icon name="sparkles" class="size-4" />
            </span>
            <div class="min-w-0">
                <h3 class="study-map-title">Session Study Map & Learning Roadmap</h3>
                <p class="text-xs text-fg-muted mt-0.5 truncate">
                    Analyzed {{ $sessionStudyMap['files_count'] }} {{ \Illuminate\Support\Str::plural('file', $sessionStudyMap['files_count']) }} · {{ $sessionStudyMap['notes_count'] }} {{ \Illuminate\Support\Str::plural('note', $sessionStudyMap['notes_count']) }} in this session
                </p>
            </div>
        </div>
        <div class="flex items-center gap-2 shrink-0">
            <x-button size="sm" variant="{{ $studyMapChatOpen ? 'secondary' : 'primary' }}" icon="sparkles" wire:click="toggleStudyMapChat">
                {{ $studyMapChatOpen ? 'Hide AI Chat' : 'AI Topic Assistant' }}
            </x-button>
            <x-button size="sm" variant="ghost" icon="rotate-cw" wire:click="generateSessionStudyMap" wire:loading.attr="aria-busy" wire:target="generateSessionStudyMap" busy-label="Analyzing…">Re-analyze</x-button>
        </div>
    </div>

    {{-- Overview --}}
    @if (! empty($sessionStudyMap['overview']))
        <p class="study-map-overview">{{ $sessionStudyMap['overview'] }}</p>
    @endif

    {{-- Accordion List Mode --}}
    @if (! empty($sessionStudyMap['stages']))
        <div class="study-map-accordion-list" role="list" aria-label="Session topic roadmap">
            @foreach ($sessionStudyMap['stages'] as $stage)
                @php
                    $tName = \App\Study\Topics::cleanMarkdown($stage['target'] ?? '');
                    $stageTitle = \App\Study\Topics::cleanMarkdown($stage['title'] ?? '');
                    $tKey = mb_strtolower(trim($tName));
                    $matchingSuggestion = $suggByName[$tKey] ?? (isset($stage['suggestion_id']) ? collect($suggestions)->firstWhere('id', $stage['suggestion_id']) : null);
                    $matchingTopic = $topicByName[$tKey] ?? null;
                    $stageKey = 'stage-'.$stage['stage'];
                @endphp

                <div class="study-map-accordion-item" :class="{ 'is-open': openAccordion === '{{ $stageKey }}' }" wire:key="study-map-item-{{ $stage['stage'] }}-{{ md5($tName) }}">
                    {{-- Accordion Header / Summary Row --}}
                    <div class="study-map-accordion-header"
                        @click="openAccordion = openAccordion === '{{ $stageKey }}' ? null : '{{ $stageKey }}'"
                        role="button"
                        tabindex="0"
                        @keydown.enter.prevent="openAccordion = openAccordion === '{{ $stageKey }}' ? null : '{{ $stageKey }}'"
                        @keydown.space.prevent="openAccordion = openAccordion === '{{ $stageKey }}' ? null : '{{ $stageKey }}'"
                        :aria-expanded="(openAccordion === '{{ $stageKey }}').toString()">
                        
                        <div class="study-map-acc-left">
                            <span class="study-map-stage-pill">{{ $stage['stage'] }}</span>
                            <div class="study-map-acc-info">
                                <span class="study-map-acc-stage-title">{{ $stageTitle }}</span>
                                <span class="study-map-acc-topic-name">{{ $tName }}</span>
                            </div>
                        </div>

                        <div class="study-map-acc-right" @click.stop>
                            @if (! empty($stage['source']))
                                <span class="study-map-source-tag" title="Source: {{ $stage['source'] }}">
                                    <x-icon name="file-text" class="size-3 shrink-0" />
                                    <span>{{ $stage['source'] }}</span>
                                </span>
                            @endif

                            @if ($matchingTopic)
                                <span class="badge-success inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full shrink-0">
                                    <x-icon name="circle-check" class="size-3" />
                                    <span>Tracked</span>
                                </span>
                            @elseif ($matchingSuggestion)
                                <x-button size="xs" variant="primary" icon="plus" wire:click.stop="addOneSuggested('{{ $matchingSuggestion->id }}')" wire:loading.attr="aria-busy" wire:target="addOneSuggested('{{ $matchingSuggestion->id }}')">
                                    Add
                                </x-button>
                            @endif

                            <button type="button" class="btn btn-ghost btn-xs p-1" @click.stop="openAccordion = openAccordion === '{{ $stageKey }}' ? null : '{{ $stageKey }}'" aria-label="Toggle details">
                                <x-icon name="chevron-down" class="size-4 study-map-chevron" ::class="{ 'is-open': openAccordion === '{{ $stageKey }}' }" />
                            </button>
                        </div>
                    </div>

                    {{-- Accordion Body / Deep Dive Details --}}
                    <div class="study-map-accordion-body" x-show="openAccordion === '{{ $stageKey }}'" x-cloak x-collapse>
                        {{-- What You Will Master --}}
                        <div>
                            <div class="study-map-section-label">What You Will Master</div>
                            <p class="study-map-body-summary">{{ $stage['summary'] ?? $stage['desc'] }}</p>
                        </div>

                        {{-- Core Concepts & Focus Points --}}
                        @if (! empty($stage['concepts']))
                            <div>
                                <div class="study-map-section-label">Key Concepts & Focus Points</div>
                                <div class="study-map-concepts-list">
                                    @foreach ($stage['concepts'] as $concept)
                                        <span class="study-map-concept-pill">
                                            <x-icon name="check" class="size-3 text-accent shrink-0" />
                                            <span>{{ $concept }}</span>
                                        </span>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        {{-- Why It Matters Callout --}}
                        @if (! empty($stage['why_it_matters']))
                            <div class="study-map-why-box">
                                <x-icon name="lightbulb" class="size-4 text-warning shrink-0 mt-0.5" />
                                <div>
                                    <strong class="text-fg font-medium">Why this matters:</strong> {{ $stage['why_it_matters'] }}
                                </div>
                            </div>
                        @endif

                        {{-- Inline Rename Form if Active --}}
                        @if ($matchingSuggestion && $editingSuggestionId === $matchingSuggestion->id)
                            <div class="p-3 rounded-lg border border-accent bg-surface-sunken space-y-2">
                                <label class="text-xs font-semibold text-fg">Rename Topic</label>
                                <div class="flex items-center gap-2">
                                    <input type="text" class="input input-sm flex-1" wire:model="editingSuggestionName" wire:keydown.enter="saveRenameSuggestion('{{ $matchingSuggestion->id }}')" wire:keydown.escape="cancelRenameSuggestion" autofocus>
                                    <x-button size="sm" variant="primary" wire:click="saveRenameSuggestion('{{ $matchingSuggestion->id }}')">Save</x-button>
                                    <x-button size="sm" variant="ghost" wire:click="cancelRenameSuggestion">Cancel</x-button>
                                </div>
                            </div>
                        @endif

                        {{-- Accordion Footer Actions --}}
                        <div class="study-map-body-actions">
                            <div class="flex flex-wrap items-center gap-2">
                                @if ($matchingSuggestion)
                                    <x-button size="sm" variant="primary" icon="plus" wire:click="addOneSuggested('{{ $matchingSuggestion->id }}')" wire:loading.attr="aria-busy" wire:target="addOneSuggested('{{ $matchingSuggestion->id }}')" busy-label="Adding…">
                                        Accept & Track Topic
                                    </x-button>
                                    <x-button size="sm" variant="secondary" icon="pencil" wire:click="startRenameSuggestion('{{ $matchingSuggestion->id }}', '{{ addslashes($tName) }}')">
                                        Rename
                                    </x-button>
                                    <x-button size="sm" variant="ghost" icon="x" wire:click="dismissOneSuggested('{{ $matchingSuggestion->id }}')">
                                        Not this topic
                                    </x-button>
                                @elseif ($matchingTopic)
                                    <span class="inline-flex items-center gap-1.5 text-xs font-medium text-success py-1 px-2.5 rounded-full bg-success-subtle border border-success">
                                        <x-icon name="circle-check" class="size-3.5" />
                                        <span>Tracked in Session ({{ $matchingTopic->shown() }})</span>
                                    </span>
                                @endif
                            </div>

                            <div class="flex items-center gap-2">
                                <x-button size="sm" variant="ghost" icon="sparkles" wire:click="askAiAboutTopic('{{ addslashes($tName) }}')" wire:loading.attr="aria-busy" wire:target="askAiAboutTopic('{{ addslashes($tName) }}')" busy-label="Asking AI…">
                                    Ask AI to elaborate
                                </x-button>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Bottom Action Bar for Suggested Topics --}}
    @if ($suggestions !== [])
        <div class="study-map-action-bar">
            <div class="flex items-center gap-2 min-w-0">
                <span class="session-kpi-icon ws-colour-purple shrink-0" style="width: 1.75rem; height: 1.75rem;" aria-hidden="true">
                    <x-icon name="sparkles" class="size-3.5" />
                </span>
                <span class="text-xs text-fg">
                    <strong>{{ count($suggestions) }} concise suggested {{ count($suggestions) === 1 ? 'topic' : 'topics' }}</strong> ready for this session
                </span>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <x-button size="sm" variant="primary" icon="plus" wire:click="addSuggested" wire:loading.attr="aria-busy" wire:target="addSuggested" busy-label="Adding…">Add all to Topics ({{ count($suggestions) }})</x-button>
                <x-button size="sm" variant="secondary" wire:click="pickSuggested" aria-expanded="{{ $picking ? 'true' : 'false' }}">Review</x-button>
            </div>
        </div>
    @elseif ($placeTopics !== [])
        <div class="study-map-action-bar">
            <div class="flex items-center gap-2 min-w-0">
                <span class="session-kpi-icon ws-colour-green shrink-0" style="width: 1.75rem; height: 1.75rem;" aria-hidden="true">
                    <x-icon name="circle-check" class="size-3.5" />
                </span>
                <span class="text-xs text-fg">
                    All <strong>{{ count($placeTopics) }} session topics</strong> are active and tracked
                </span>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <x-button size="sm" variant="secondary" icon="play" wire:click="studyModule">Study Session</x-button>
            </div>
        </div>
    @endif

    {{-- Real-Time AI Chat Assistant Panel --}}
    @if ($studyMapChatOpen)
        <div class="study-map-chat-panel" aria-label="AI Topic Assistant chat">
            <div class="study-map-chat-head">
                <div class="flex items-center gap-2 min-w-0">
                    <span class="session-kpi-icon ws-colour-purple shrink-0" style="width: 1.75rem; height: 1.75rem;" aria-hidden="true">
                        <x-icon name="sparkles" class="size-3.5" />
                    </span>
                    <div>
                        <h4 class="text-sm font-semibold text-fg">AI Topic Assistant & Roadmap Refiner</h4>
                        <p class="text-xs text-fg-muted">Ask questions, explore concepts, or ask the AI to modify, split, rename, or adjust topics before accepting.</p>
                    </div>
                </div>
                <x-button size="xs" variant="ghost" icon="x" wire:click="toggleStudyMapChat" title="Close chat" aria-label="Close chat" />
            </div>

            {{-- Quick Ask Prompt Chips --}}
            <div class="study-map-chips" role="toolbar" aria-label="Quick AI prompt chips">
                <button type="button" class="study-map-chip" wire:click="quickStudyMapPrompt('Please provide a detailed breakdown and study advice for all the suggested topics in this session.')">
                    <x-icon name="sparkles" class="size-3.5 text-accent shrink-0" />
                    <span>Elaborate on all topics</span>
                </button>
                <button type="button" class="study-map-chip" wire:click="quickStudyMapPrompt('Please simplify and condense this session into 3 high-impact, concise topics.')">
                    <x-icon name="layers" class="size-3.5 text-warning shrink-0" />
                    <span>Simplify to 3 topics</span>
                </button>
                <button type="button" class="study-map-chip" wire:click="quickStudyMapPrompt('Please add more emphasis on hands-on practical lab exercises and commands to the topics.')">
                    <x-icon name="flask-conical" class="size-3.5 text-success shrink-0" />
                    <span>Focus on hands-on labs</span>
                </button>
                <button type="button" class="study-map-chip" wire:click="quickStudyMapPrompt('Based on the lecture and lab files, what should I study first and what are the prerequisites?')">
                    <x-icon name="graduation-cap" class="size-3.5 text-info shrink-0" />
                    <span>What should I study first?</span>
                </button>
            </div>

            {{-- Chat Log Stream --}}
            <div class="study-map-chat-log" role="log" aria-label="Conversation with AI Topic Assistant" tabindex="0"
                x-data
                x-ref="mapChatLog"
                x-init="$watch('$wire.studyMapChat', () => $nextTick(() => { if ($refs.mapChatLog) $refs.mapChatLog.scrollTop = $refs.mapChatLog.scrollHeight; }))">
                @foreach ($studyMapChat as $i => $msg)
                    <div class="study-map-chat-turn {{ $msg['role'] === 'user' ? 'is-user' : 'is-assistant' }}" wire:key="study-chat-msg-{{ $i }}">
                        <div class="study-map-chat-bubble">
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
                <div wire:loading wire:target="sendStudyMapChat,quickStudyMapPrompt,askAiAboutTopic" class="study-map-chat-thinking">
                    <span class="ai-dots" aria-hidden="true"><span></span><span></span><span></span></span>
                    <span>AI is thinking & refining roadmap…</span>
                </div>
            </div>

            {{-- ViStud Standard Robust Compose Interface --}}
            <form wire:submit.prevent="sendStudyMapChat" novalidate class="w-full"
                x-data="{
                    formatOpen: false,
                    resize() {
                        const el = this.$refs.box;
                        if (!el) return;
                        el.style.height = 'auto';
                        el.style.height = Math.min(el.scrollHeight, 180) + 'px';
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
                                $wire.sendStudyMapChat();
                                return;
                            }
                        }
                        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
                            event.preventDefault();
                            $wire.sendStudyMapChat();
                        }
                    }
                }"
                x-init="
                    $nextTick(() => resize());
                    $watch('$wire.studyMapChatText', (val) => {
                        if (!val) {
                            $nextTick(() => {
                                if ($refs.box) $refs.box.style.height = 'auto';
                            });
                        }
                    });
                ">
                
                <div class="chat-compose">
                    <label for="study-map-chat-input" class="sr-only">Message the AI Study Guide</label>
                    <textarea id="study-map-chat-input" class="chat-box" rows="2"
                        wire:model="studyMapChatText"
                        x-ref="box"
                        placeholder="Ask the AI to elaborate on a topic, or say 'Split topic 2 into two topics' or 'Rename topic 1 to...'..."
                        wire:loading.attr="disabled" wire:target="sendStudyMapChat"
                        x-on:input="resize()"
                        x-on:keydown="handleKeydown($event)"></textarea>

                    <div class="chat-compose-bar">
                        <div class="chat-compose-tools" role="toolbar" aria-label="Formatting tools">
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
                            <button type="submit" class="btn btn-primary chat-send" wire:loading.attr="aria-busy" wire:target="sendStudyMapChat" title="Send message to AI" aria-label="Send message to AI">
                                <span wire:loading.remove wire:target="sendStudyMapChat"><x-icon name="arrow-up" class="size-4" /></span>
                                <span wire:loading wire:target="sendStudyMapChat"><x-icon name="loader-circle" class="size-4 animate-spin" /></span>
                                <span class="sr-only">Send</span>
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    @endif
</div>
