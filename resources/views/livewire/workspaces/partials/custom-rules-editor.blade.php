{{--
    Advanced Note Editor for Custom Tutor Rules (Do & Don't)
    Allows writing and organizing custom numbered instructions (1, 2, 3...)
--}}
<div class="space-y-4"
    x-data="{
        view: 'split',
        showPreview: false,
        doText: @entangle('customDo'),
        dontText: @entangle('customDont'),

        countRules(text) {
            if (!text || !text.trim()) return 0;
            return text.split('\n').filter(l => l.trim().length > 0).length;
        },

        insertRule(which, textToAdd) {
            const current = (which === 'do' ? this.doText : this.dontText) || '';
            const lines = current ? current.split('\n') : [];
            const nonBlank = lines.filter(l => l.trim().length > 0);
            const nextNum = nonBlank.length + 1;
            const newLine = `${nextNum}. ${textToAdd}`;
            const updated = current.trim() ? `${current.trim()}\n${newLine}` : newLine;
            this.updateValue(which, updated);
        },

        formatNumbered(which) {
            const current = (which === 'do' ? this.doText : this.dontText) || '';
            if (!current.trim()) return;
            const lines = current.split('\n');
            let num = 1;
            const formatted = lines.map(line => {
                const trimmed = line.trim();
                if (!trimmed) return '';
                const clean = trimmed.replace(/^(\d+[\.\)]|\-|\*|\•)\s*/, '');
                return `${num++}. ${clean}`;
            }).filter(l => l.length > 0).join('\n');
            this.updateValue(which, formatted);
        },

        formatBullet(which) {
            const current = (which === 'do' ? this.doText : this.dontText) || '';
            if (!current.trim()) return;
            const lines = current.split('\n');
            const formatted = lines.map(line => {
                const trimmed = line.trim();
                if (!trimmed) return '';
                const clean = trimmed.replace(/^(\d+[\.\)]|\-|\*|\•)\s*/, '');
                return `- ${clean}`;
            }).filter(l => l.length > 0).join('\n');
            this.updateValue(which, formatted);
        },

        wrapBold(textareaRef, which) {
            const el = this.$refs[textareaRef];
            if (!el) return;
            const start = el.selectionStart;
            const end = el.selectionEnd;
            const val = el.value || '';
            const selected = val.substring(start, end);
            const replacement = selected ? `**${selected}**` : '**bold text**';
            const newVal = val.substring(0, start) + replacement + val.substring(end);
            el.value = newVal;
            this.updateValue(which, newVal);
            this.$nextTick(() => {
                el.focus();
                el.setSelectionRange(start + 2, start + 2 + (selected ? selected.length : 9));
            });
        },

        addNewLine(which, textareaRef) {
            const current = (which === 'do' ? this.doText : this.dontText) || '';
            const lines = current ? current.split('\n') : [];
            const nonBlank = lines.filter(l => l.trim().length > 0);
            const nextNum = nonBlank.length + 1;
            const prefix = `${nextNum}. `;
            const updated = current.trim() ? `${current.trim()}\n${prefix}` : prefix;
            this.updateValue(which, updated);
            this.$nextTick(() => {
                const el = this.$refs[textareaRef];
                if (el) {
                    el.focus();
                    el.selectionStart = el.selectionEnd = el.value.length;
                }
            });
        },

        handleKeyDown(e, which, textareaRef) {
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

    {{-- Top bar: navigation & view switch --}}
    <div class="flex flex-wrap items-center justify-between gap-3 pb-2 border-b border-border-subtle">
        <div class="min-w-0">
            <button type="button" class="inline-flex items-center gap-1.5 text-xs text-accent hover:underline font-medium" wire:click="editTeaching">
                <x-icon name="arrow-left" class="size-3.5" />
                <span>Back to general teaching settings</span>
            </button>
            <p class="text-xs text-fg-muted mt-0.5">Use numbered lists (1, 2, 3...) for clear step-by-step tutor guidance. If left empty, nothing is added to the prompt.</p>
        </div>

        {{-- View selector (Split / Do / Don't) --}}
        <div class="segmented" role="tablist" aria-label="Editor view">
            <button type="button" class="segmented-btn" x-bind:class="{ 'is-active': view === 'split' }" x-on:click="view = 'split'">
                <span>Split view</span>
            </button>
            <button type="button" class="segmented-btn" x-bind:class="{ 'is-active': view === 'do' }" x-on:click="view = 'do'">
                <span class="inline-block size-1.5 rounded-full bg-success"></span>
                <span>Do</span>
                <span class="text-3xs text-fg-subtle" x-text="'(' + countRules(doText) + ')'"></span>
            </button>
            <button type="button" class="segmented-btn" x-bind:class="{ 'is-active': view === 'dont' }" x-on:click="view = 'dont'">
                <span class="inline-block size-1.5 rounded-full bg-danger"></span>
                <span>Don't</span>
                <span class="text-3xs text-fg-subtle" x-text="'(' + countRules(dontText) + ')'"></span>
            </button>
        </div>
    </div>

    {{-- Editor grid --}}
    <div class="grid gap-4" x-bind:class="view === 'split' ? 'md:grid-cols-2' : 'grid-cols-1'">

        {{-- Section 1: What to do --}}
        <div class="note-rule-card" x-show="view === 'split' || view === 'do'">
            <div class="note-rule-header">
                <div class="flex items-center gap-2 min-w-0">
                    <span class="inline-block size-2 rounded-full bg-success shrink-0"></span>
                    <span class="text-xs font-semibold text-fg uppercase tracking-wider">What to do</span>
                    <span class="text-3xs px-1.5 py-0.5 rounded-full bg-surface-sunken text-fg-muted font-medium" x-text="countRules(doText) + ' rules'"></span>
                </div>
                <span class="text-3xs text-fg-subtle" x-text="((doText || '').length) + ' / {{ \App\Study\Tutoring::MAX_CUSTOM }}'"></span>
            </div>

            {{-- Toolbar --}}
            <div class="note-rule-toolbar">
                <div class="flex items-center gap-1">
                    <button type="button" class="note-rule-tool-btn" x-on:click="formatNumbered('do')" title="Format all lines as a numbered list (1, 2, 3...)">
                        <span class="font-bold text-xs">1.</span>
                        <span class="text-3xs">Number</span>
                    </button>
                    <button type="button" class="note-rule-tool-btn" x-on:click="formatBullet('do')" title="Format all lines as bullet points">
                        <span class="font-bold text-xs">•</span>
                        <span class="text-3xs">Bullet</span>
                    </button>
                    <button type="button" class="note-rule-tool-btn" x-on:click="wrapBold('doArea', 'do')" title="Bold selected text (**text**)">
                        <span class="font-bold text-xs">B</span>
                    </button>
                    <button type="button" class="note-rule-tool-btn" x-on:click="addNewLine('do', 'doArea')" title="Add next numbered rule">
                        <x-icon name="plus" class="size-3" />
                        <span class="text-3xs">Add item</span>
                    </button>
                </div>
                <button type="button" class="note-rule-tool-btn text-fg-subtle hover:text-danger" x-show="(doText || '').trim().length > 0" x-on:click="clear('do')" title="Clear What to do rules">
                    <span class="text-3xs">Clear</span>
                </button>
            </div>

            {{-- Textarea --}}
            <div class="relative flex-1">
                <textarea id="custom-rules-do"
                    x-ref="doArea"
                    x-model="doText"
                    x-on:keydown="handleKeyDown($event, 'do', 'doArea')"
                    class="note-rule-textarea"
                    rows="8"
                    maxlength="{{ \App\Study\Tutoring::MAX_CUSTOM }}"
                    placeholder="1. Break concepts into small, numbered steps
2. Lead with intuitive real-world analogies
3. Always ask a gentle check question before moving on..."></textarea>
            </div>

            {{-- Preset chips --}}
            <div class="note-rule-chips">
                <span class="text-3xs text-fg-subtle font-medium mr-1">Suggestions:</span>
                <button type="button" class="note-rule-chip" x-on:click="insertRule('do', 'Break concepts down into step-by-step numbered points')">+ Step by step</button>
                <button type="button" class="note-rule-chip" x-on:click="insertRule('do', 'Lead with concrete real-world analogies before formal terms')">+ Analogies</button>
                <button type="button" class="note-rule-chip" x-on:click="insertRule('do', 'Always include practical, commented code examples')">+ Code examples</button>
                <button type="button" class="note-rule-chip" x-on:click="insertRule('do', 'Ask gentle check questions to confirm basic recall first')">+ Gentle check</button>
                <button type="button" class="note-rule-chip" x-on:click="insertRule('do', 'Use plain conversational English and avoid heavy shorthand')">+ Plain English</button>
            </div>
            @error('customDo') <p class="field-error px-3 pb-2">{{ $message }}</p> @enderror
        </div>

        {{-- Section 2: What NOT to do --}}
        <div class="note-rule-card" x-show="view === 'split' || view === 'dont'">
            <div class="note-rule-header">
                <div class="flex items-center gap-2 min-w-0">
                    <span class="inline-block size-2 rounded-full bg-danger shrink-0"></span>
                    <span class="text-xs font-semibold text-fg uppercase tracking-wider">What NOT to do</span>
                    <span class="text-3xs px-1.5 py-0.5 rounded-full bg-surface-sunken text-fg-muted font-medium" x-text="countRules(dontText) + ' rules'"></span>
                </div>
                <span class="text-3xs text-fg-subtle" x-text="((dontText || '').length) + ' / {{ \App\Study\Tutoring::MAX_CUSTOM }}'"></span>
            </div>

            {{-- Toolbar --}}
            <div class="note-rule-toolbar">
                <div class="flex items-center gap-1">
                    <button type="button" class="note-rule-tool-btn" x-on:click="formatNumbered('dont')" title="Format all lines as a numbered list (1, 2, 3...)">
                        <span class="font-bold text-xs">1.</span>
                        <span class="text-3xs">Number</span>
                    </button>
                    <button type="button" class="note-rule-tool-btn" x-on:click="formatBullet('dont')" title="Format all lines as bullet points">
                        <span class="font-bold text-xs">•</span>
                        <span class="text-3xs">Bullet</span>
                    </button>
                    <button type="button" class="note-rule-tool-btn" x-on:click="wrapBold('dontArea', 'dont')" title="Bold selected text (**text**)">
                        <span class="font-bold text-xs">B</span>
                    </button>
                    <button type="button" class="note-rule-tool-btn" x-on:click="addNewLine('dont', 'dontArea')" title="Add next numbered rule">
                        <x-icon name="plus" class="size-3" />
                        <span class="text-3xs">Add item</span>
                    </button>
                </div>
                <button type="button" class="note-rule-tool-btn text-fg-subtle hover:text-danger" x-show="(dontText || '').trim().length > 0" x-on:click="clear('dont')" title="Clear What NOT to do rules">
                    <span class="text-3xs">Clear</span>
                </button>
            </div>

            {{-- Textarea --}}
            <div class="relative flex-1">
                <textarea id="custom-rules-dont"
                    x-ref="dontArea"
                    x-model="dontText"
                    x-on:keydown="handleKeyDown($event, 'dont', 'dontArea')"
                    class="note-rule-textarea"
                    rows="8"
                    maxlength="{{ \App\Study\Tutoring::MAX_CUSTOM }}"
                    placeholder="1. Never give full solutions right away; provide hints
2. Do not use unexplained academic jargon
3. Avoid multiple heavy topics in one reply..."></textarea>
            </div>

            {{-- Preset chips --}}
            <div class="note-rule-chips">
                <span class="text-3xs text-fg-subtle font-medium mr-1">Suggestions:</span>
                <button type="button" class="note-rule-chip" x-on:click="insertRule('dont', 'Never provide direct solutions immediately; guide with hints')">+ No direct answers</button>
                <button type="button" class="note-rule-chip" x-on:click="insertRule('dont', 'Do not use unexplained technical acronyms or jargon')">+ No jargon</button>
                <button type="button" class="note-rule-chip" x-on:click="insertRule('dont', 'Avoid dense LaTeX equations; write formulas in plain text')">+ No LaTeX</button>
                <button type="button" class="note-rule-chip" x-on:click="insertRule('dont', 'Do not rush through multiple large topics in one message')">+ Don't rush</button>
                <button type="button" class="note-rule-chip" x-on:click="insertRule('dont', 'Avoid excessive emojis and conversational fluff')">+ No emojis</button>
            </div>
            @error('customDont') <p class="field-error px-3 pb-2">{{ $message }}</p> @enderror
        </div>
    </div>

    {{-- Collapsible Live Prompt Preview --}}
    <div class="rounded-lg border border-border-subtle bg-surface-sunken p-3 text-xs">
        <div class="flex items-center justify-between cursor-pointer select-none" x-on:click="showPreview = ! showPreview">
            <span class="font-medium text-fg flex items-center gap-1.5">
                <x-icon name="sparkles" class="size-3 text-accent shrink-0" />
                <span>Preview prompt injected to AI tutor</span>
            </span>
            <button type="button" class="text-accent hover:underline text-3xs font-medium" x-text="showPreview ? 'Hide preview' : 'Show preview'"></button>
        </div>

        <div x-show="showPreview" x-cloak class="mt-2 pt-2 border-t border-border-subtle">
            <template x-if="! (doText || '').trim() && ! (dontText || '').trim()">
                <p class="text-fg-subtle italic">Both fields are empty: nothing will be added to the AI tutor's prompt.</p>
            </template>
            <template x-if="(doText || '').trim() || (dontText || '').trim()">
                <div class="space-y-2 font-mono text-3xs text-fg-muted whitespace-pre-wrap leading-relaxed">
                    <p class="text-fg font-semibold font-sans text-xs">## Custom instructions</p>
                    <template x-if="(doText || '').trim()">
                        <div class="p-2 rounded bg-surface border border-border">
                            <span class="text-success font-bold font-sans text-xs">What to do:</span>
                            <div x-text="doText.trim()"></div>
                        </div>
                    </template>
                    <template x-if="(dontText || '').trim()">
                        <div class="p-2 rounded bg-surface border border-border">
                            <span class="text-danger font-bold font-sans text-xs">What NOT to do:</span>
                            <div x-text="dontText.trim()"></div>
                        </div>
                    </template>
                </div>
            </template>
        </div>
    </div>
</div>
