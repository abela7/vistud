{{--
    Custom tutor rules (Do & Don't): one editor at a time, so the dialog stays calm.
    Same look as the AI Tutor Tuning dropdown (session-tutor-* classes).
--}}
@php
    $ruleEditors = [
        'do' => [
            'label' => 'What to do',
            'hint' => 'Things the tutor should always do when it teaches you.',
            'dot' => 'bg-success',
            'error' => 'customDo',
            'placeholder' => "1. Break concepts into small, numbered steps\n2. Lead with intuitive real-world analogies\n3. Always ask a gentle check question before moving on...",
            'suggestions' => [
                'Step by step' => 'Break concepts down into step-by-step numbered points',
                'Analogies' => 'Lead with concrete real-world analogies before formal terms',
                'Code examples' => 'Always include practical, commented code examples',
                'Gentle check' => 'Ask gentle check questions to confirm basic recall first',
                'Plain English' => 'Use plain conversational English and avoid heavy shorthand',
            ],
        ],
        'dont' => [
            'label' => 'What NOT to do',
            'hint' => 'Things the tutor should never do.',
            'dot' => 'bg-danger',
            'error' => 'customDont',
            'placeholder' => "1. Never give full solutions right away; provide hints\n2. Do not use unexplained academic jargon\n3. Avoid multiple heavy topics in one reply...",
            'suggestions' => [
                'No direct answers' => 'Never provide direct solutions immediately; guide with hints',
                'No jargon' => 'Do not use unexplained technical acronyms or jargon',
                'No LaTeX' => 'Avoid dense LaTeX equations; write formulas in plain text',
                'Don\'t rush' => 'Do not rush through multiple large topics in one message',
                'No emojis' => 'Avoid excessive emojis and conversational fluff',
            ],
        ],
    ];
@endphp
<div class="session-rules"
    x-data="{
        view: 'do',
        showPreview: false,
        doText: @entangle('customDo'),
        dontText: @entangle('customDont'),

        countRules(text) {
            if (!text || !text.trim()) return 0;
            return text.split('\n').filter(l => l.trim().length > 0).length;
        },

        get(which) {
            return (which === 'do' ? this.doText : this.dontText) || '';
        },

        insertRule(which, textToAdd) {
            const current = this.get(which);
            const nextNum = current.split('\n').filter(l => l.trim().length > 0).length + 1;
            const newLine = `${nextNum}. ${textToAdd}`;
            this.updateValue(which, current.trim() ? `${current.trim()}\n${newLine}` : newLine);
        },

        formatNumbered(which) {
            const current = this.get(which);
            if (!current.trim()) return;
            let num = 1;
            const formatted = current.split('\n').map(line => {
                const trimmed = line.trim();
                if (!trimmed) return '';
                const clean = trimmed.replace(/^(\d+[\.\)]|\-|\*|\•)\s*/, '');
                return `${num++}. ${clean}`;
            }).filter(l => l.length > 0).join('\n');
            this.updateValue(which, formatted);
        },

        handleKeyDown(e, which) {
            const el = e.target;
            if (e.key === 'Enter' && !e.shiftKey) {
                const cursorPos = el.selectionStart;
                const textBefore = el.value.substring(0, cursorPos);
                const currentLine = textBefore.split('\n').pop();
                const numMatch = currentLine.match(/^(\s*)(\d+)([\.\)])\s*(.*)$/);
                if (numMatch) {
                    const indent = numMatch[1];
                    const num = parseInt(numMatch[2], 10);
                    const delimiter = numMatch[3];
                    const content = numMatch[4];
                    if (content.trim() === '') {
                        e.preventDefault();
                        const lineStart = cursorPos - currentLine.length;
                        const newVal = el.value.substring(0, lineStart) + el.value.substring(cursorPos);
                        el.value = newVal;
                        el.selectionStart = el.selectionEnd = lineStart;
                        this.updateValue(which, newVal);
                        return;
                    }
                    e.preventDefault();
                    const nextNumStr = `\n${indent}${num + 1}${delimiter} `;
                    const textAfter = el.value.substring(cursorPos);
                    const newVal = textBefore + nextNumStr + textAfter;
                    el.value = newVal;
                    const newPos = cursorPos + nextNumStr.length;
                    el.selectionStart = el.selectionEnd = newPos;
                    this.updateValue(which, newVal);
                    return;
                }
                const bulletMatch = currentLine.match(/^(\s*)([\-\*•])\s*(.*)$/);
                if (bulletMatch) {
                    const indent = bulletMatch[1];
                    const bullet = bulletMatch[2];
                    const content = bulletMatch[3];
                    if (content.trim() === '') {
                        e.preventDefault();
                        const lineStart = cursorPos - currentLine.length;
                        const newVal = el.value.substring(0, lineStart) + el.value.substring(cursorPos);
                        el.value = newVal;
                        el.selectionStart = el.selectionEnd = lineStart;
                        this.updateValue(which, newVal);
                        return;
                    }
                    e.preventDefault();
                    const nextBulletStr = `\n${indent}${bullet} `;
                    const textAfter = el.value.substring(cursorPos);
                    const newVal = textBefore + nextBulletStr + textAfter;
                    el.value = newVal;
                    const newPos = cursorPos + nextBulletStr.length;
                    el.selectionStart = el.selectionEnd = newPos;
                    this.updateValue(which, newVal);
                    return;
                }
            }
            if (e.key === 'Tab') {
                e.preventDefault();
                const start = el.selectionStart;
                const end = el.selectionEnd;
                const val = el.value;
                const newVal = val.substring(0, start) + '  ' + val.substring(end);
                el.value = newVal;
                el.selectionStart = el.selectionEnd = start + 2;
                this.updateValue(which, newVal);
            }
        },

        updateValue(which, val) {
            if (which === 'do') {
                this.doText = val;
            } else {
                this.dontText = val;
            }
        },

        clear(which) {
            this.updateValue(which, '');
        }
    }">

    <div class="session-rules-intro">
        <button type="button" class="session-rules-back" wire:click="editTeaching">
            <x-icon name="arrow-left" class="size-4" />
            <span>Back to teaching settings</span>
        </button>
        <p class="session-tutor-section-hint">Write short rules, one per line. Whatever you add is sent to the tutor with every message; if you leave both boxes empty, nothing is added.</p>
    </div>

    {{-- Which list you are editing --}}
    <div class="session-tutor-options" role="group" aria-label="Which rules to edit">
        @foreach ($ruleEditors as $key => $editor)
            <button type="button"
                class="session-tutor-choice-btn session-rules-tab"
                x-bind:class="{ 'is-active': view === '{{ $key }}' }"
                x-bind:aria-pressed="(view === '{{ $key }}').toString()"
                x-on:click="view = '{{ $key }}'">
                <span class="inline-block size-2 rounded-full {{ $editor['dot'] }} shrink-0"></span>
                <span>{{ $key === 'do' ? 'Do' : 'Don\'t' }}</span>
                <span class="session-rules-count" x-text="countRules({{ $key }}Text)"></span>
            </button>
        @endforeach
    </div>

    @foreach ($ruleEditors as $key => $editor)
        <section class="session-tutor-popover-section" x-show="view === '{{ $key }}'" aria-labelledby="custom-rules-{{ $key }}-label">
            <div class="flex items-baseline justify-between gap-2">
                <h3 id="custom-rules-{{ $key }}-label" class="session-tutor-section-label">{{ $editor['label'] }}</h3>
                <span class="session-tutor-section-meta" x-text="({{ $key }}Text || '').length + ' / {{ \App\Study\Tutoring::MAX_CUSTOM }}'"></span>
            </div>
            <p class="session-tutor-section-hint">{{ $editor['hint'] }}</p>

            <div class="note-rule-card">
                <textarea id="custom-rules-{{ $key }}"
                    x-ref="{{ $key }}Area"
                    x-model="{{ $key }}Text"
                    x-on:keydown="handleKeyDown($event, '{{ $key }}')"
                    class="note-rule-textarea"
                    rows="7"
                    aria-labelledby="custom-rules-{{ $key }}-label"
                    maxlength="{{ \App\Study\Tutoring::MAX_CUSTOM }}"
                    placeholder="{{ $editor['placeholder'] }}"></textarea>
                <div class="note-rule-toolbar">
                    <button type="button" class="note-rule-tool-btn" x-on:click="formatNumbered('{{ $key }}')" title="Number every line: 1, 2, 3...">
                        <x-icon name="list-ordered" class="size-4" />
                        <span>Number the lines</span>
                    </button>
                    <button type="button" class="note-rule-tool-btn" x-show="({{ $key }}Text || '').trim().length > 0" x-on:click="clear('{{ $key }}')">
                        <span>Clear</span>
                    </button>
                </div>
            </div>
            @error($editor['error']) <p class="field-error">{{ $message }}</p> @enderror

            <div class="session-rules-suggestions">
                <h4 class="session-tutor-section-label">Tap to add</h4>
                <div class="note-rule-chips">
                    @foreach ($editor['suggestions'] as $chipLabel => $rule)
                        <button type="button" class="note-rule-chip" x-on:click="insertRule('{{ $key }}', @js($rule))" title="{{ $rule }}">+ {{ $chipLabel }}</button>
                    @endforeach
                </div>
            </div>
        </section>
    @endforeach

    {{-- What the tutor will actually receive --}}
    <div class="session-tutor-popover-custom">
        <button type="button" class="session-rules-preview-toggle" x-on:click="showPreview = ! showPreview" x-bind:aria-expanded="showPreview.toString()" aria-controls="custom-rules-preview">
            <x-icon name="sparkles" class="size-4 text-accent shrink-0" />
            <span class="flex-1 text-left">See what the tutor receives</span>
            <x-icon name="chevron-down" class="size-4 text-fg-subtle shrink-0 transition-transform" x-bind:class="showPreview && 'rotate-180'" />
        </button>

        <div id="custom-rules-preview" x-show="showPreview" x-cloak class="session-rules-preview">
            <template x-if="! (doText || '').trim() && ! (dontText || '').trim()">
                <p class="session-tutor-section-hint">Both boxes are empty, so nothing will be added to the tutor's prompt.</p>
            </template>
            <template x-if="(doText || '').trim() || (dontText || '').trim()">
                <div class="space-y-2 text-sm text-fg-muted leading-relaxed">
                    <template x-if="(doText || '').trim()">
                        <div class="session-rules-preview-block">
                            <span class="block text-success font-semibold">What to do</span>
                            <div class="whitespace-pre-wrap" x-text="doText.trim()"></div>
                        </div>
                    </template>
                    <template x-if="(dontText || '').trim()">
                        <div class="session-rules-preview-block">
                            <span class="block text-danger font-semibold">What NOT to do</span>
                            <div class="whitespace-pre-wrap" x-text="dontText.trim()"></div>
                        </div>
                    </template>
                </div>
            </template>
        </div>
    </div>
</div>
