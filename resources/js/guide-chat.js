/*
| The course guide's talk (App\Livewire\Workspaces\GuideChat), in the tutor chat's way: the student's words show at once,
| the guide's answer streams in as it is written (wire:stream into "guide-answer"), the log follows the newest line
| unless the student has scrolled up, and finished replies get their code blocks, formulas and diagrams
| (resources/js/chat-markdown.js). The box writes Markdown with shortcuts (Ctrl+B, Ctrl+I, Ctrl+E), continues lists,
| takes a pasted or dropped screenshot, and can be dictated into.
*/

import { decorateReplies } from './chat-markdown.js';

export function guideChat() {
    return {
        formatOpen: false,
        pending: '',
        pendingPictures: 0,
        live: false,
        follow: true,
        transcribing: false,
        interimSpeech: '',
        recognition: null,

        init() {
            // The talk fills the window from where it starts: measured now, and again when the window or what is above it changes.
            const measure = () => this.measure();
            window.addEventListener('resize', measure, { passive: true });
            const chat = this.$root.querySelector('.guide-chat');
            if (chat && window.ResizeObserver) new ResizeObserver(measure).observe(document.body);
            // Whatever is added to the talk (a turn, the streamed answer, the proposal) keeps the newest line in view,
            // unless the student has scrolled up to read.
            if (this.$refs.log && window.MutationObserver) {
                new MutationObserver(() => this.stick()).observe(this.$refs.log, { childList: true, subtree: true, characterData: true });
            }
            this.$nextTick(() => {
                this.measure();
                this.resize();
                this.decorate();
                this.stick();
            });
            this.$watch('$wire.text', (value) => {
                if (!value) this.$nextTick(() => this.resize());
            });
        },

        /** Sends what is in the box: shown at once, then the guide's answer as it streams in. */
        submit() {
            if (this.live) return;
            this.stopDictation(true);
            const words = (this.$wire.text || '').trim();
            this.pending = words;
            this.pendingPictures = this.$root.querySelectorAll('.guide-pictures > li').length;
            this.live = true;
            this.follow = true;
            // The box empties at once: the words show in the talk, not left waiting in the box. If they can't go, the
            // page puts them back (GuideChat::send).
            this.$wire.text = '';
            this.$nextTick(() => {
                this.stick();
                this.resize();
            });
            this.$wire.send(words).finally(() => this.settle());
        },

        /** A button's answer or Skip: no text to show first, the guide's next question comes back at once. */
        pick(call) {
            if (this.live) return;
            this.follow = true;
            call().finally(() => this.settle());
        },

        measure() {
            const chat = this.$root.querySelector('.guide-chat');
            if (!chat) return;
            const top = chat.getBoundingClientRect().top + window.scrollY;
            // On the page's root: Livewire redraws the component's own attributes on every answer, and would drop it.
            const root = document.documentElement;
            root.style.setProperty('--guide-top', `${Math.round(top)}px`);
            // Whatever the page has below the talk (its padding, a footer line) is taken off too, so only the talk scrolls.
            root.style.setProperty('--guide-trim', '16px');
            requestAnimationFrame(() => {
                const over = root.scrollHeight - root.clientHeight;
                if (over > 0) root.style.setProperty('--guide-trim', `${16 + over}px`);
            });
            // The talk's height changed: the newest line stays in view.
            this.$nextTick(() => this.stick());
        },

        settle() {
            this.pending = '';
            this.pendingPictures = 0;
            this.live = false;
            this.$nextTick(() => {
                this.decorate();
                this.stick();
                this.resize();
            });
        },

        decorate() {
            const replies = [...(this.$refs.log?.querySelectorAll('[data-turn] .chat-markdown') ?? [])];
            decorateReplies(replies).then(() => this.stick());
        },

        onScroll() {
            const log = this.$refs.log;
            if (!log) return;
            this.follow = log.scrollHeight - log.scrollTop - log.clientHeight < 48;
        },

        stick() {
            const log = this.$refs.log;
            if (log && this.follow) log.scrollTop = log.scrollHeight;
        },

        latest() {
            this.follow = true;
            this.stick();
        },

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
            const value = el.value || '';
            const selected = value.substring(start, end) || placeholder;
            const replacement = before + selected + after;
            el.value = value.substring(0, start) + replacement + value.substring(end);
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.focus();
            const at = selected ? start + replacement.length : start + before.length;
            el.setSelectionRange(at, at);
            this.$nextTick(() => this.resize());
        },

        toggleList() {
            const el = this.$refs.box;
            if (!el) return;
            const value = el.value || '';
            const start = el.selectionStart ?? 0;
            const end = el.selectionEnd ?? 0;
            const before = value.lastIndexOf('\n', start - 1);
            const lineStart = before === -1 ? 0 : before + 1;
            const after = value.indexOf('\n', end);
            const lineEnd = after === -1 ? value.length : after;
            const lines = value.slice(lineStart, lineEnd).split('\n');
            const bulleted = lines.every((line) => /^(\s*)[-*]\s+/.test(line));
            const replacement = (bulleted ? lines.map((line) => line.replace(/^(\s*)[-*]\s+/, '$1')) : lines.map((line) => (/^(\s*)[-*]\s+/.test(line) ? line : '- ' + line))).join('\n');
            el.value = value.slice(0, lineStart) + replacement + value.slice(lineEnd);
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.focus();
            const at = lineStart + replacement.length;
            el.setSelectionRange(at, at);
            this.$nextTick(() => this.resize());
        },

        keydown(event) {
            const el = this.$refs.box;
            if (!el) return;
            if (event.ctrlKey || event.metaKey) {
                const shortcuts = { b: ['**', '**', 'bold text'], i: ['*', '*', 'italic text'], e: ['`', '`', 'code'] };
                const shortcut = shortcuts[event.key.toLowerCase()];
                if (shortcut) {
                    event.preventDefault();
                    this.wrap(...shortcut);
                    return;
                }
                if (event.key === 'Enter') {
                    event.preventDefault();
                    this.submit();
                    return;
                }
            }
            if (event.key !== 'Enter' || event.isComposing) return;

            // In a list, Shift+Enter continues it and Enter on an empty item ends it.
            const value = el.value || '';
            const start = el.selectionStart ?? 0;
            const end = el.selectionEnd ?? 0;
            const before = value.lastIndexOf('\n', start - 1);
            const lineStart = before === -1 ? 0 : before + 1;
            const after = value.indexOf('\n', start);
            const lineEnd = after === -1 ? value.length : after;
            const list = value.slice(lineStart, lineEnd).match(/^(\s*)([-*]|\d+\.)\s*(.*)$/);
            if (list) {
                const [, indent, marker, text] = list;
                if (text.trim() === '') {
                    event.preventDefault();
                    el.value = value.slice(0, lineStart) + value.slice(lineEnd);
                    el.setSelectionRange(lineStart, lineStart);
                    el.dispatchEvent(new Event('input', { bubbles: true }));
                    this.$nextTick(() => this.resize());
                    return;
                }
                if (event.shiftKey) {
                    event.preventDefault();
                    const next = /^\d+\.$/.test(marker) ? `${parseInt(marker, 10) + 1}.` : marker;
                    const continuation = `\n${indent}${next} `;
                    el.value = value.slice(0, start) + continuation + value.slice(end);
                    el.setSelectionRange(start + continuation.length, start + continuation.length);
                    el.dispatchEvent(new Event('input', { bubbles: true }));
                    this.$nextTick(() => this.resize());
                    return;
                }
            }
            if (!event.shiftKey) {
                event.preventDefault();
                this.submit();
            }
        },

        /** A screenshot pasted or dropped on the box goes with the next message. */
        take(event) {
            const files = [...((event.clipboardData ?? event.dataTransfer)?.files ?? [])].filter((file) => file.type.startsWith('image/'));
            if (files.length > 0) {
                event.preventDefault();
                this.$wire.uploadMultiple('pictures', files);
            }
        },

        toggleDictation() {
            if (this.transcribing) this.stopDictation(true);
            else this.startDictation();
        },

        startDictation() {
            const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            if (!Recognition) {
                window.alert?.('Speaking into the box isn\'t supported in this browser.');
                return;
            }
            try {
                this.recognition?.abort();
            } catch {}
            const recognition = new Recognition();
            recognition.continuous = true;
            recognition.interimResults = true;
            recognition.lang = document.documentElement.lang || navigator.language || 'en-US';
            recognition.onstart = () => {
                this.transcribing = true;
                this.interimSpeech = '';
            };
            recognition.onresult = (event) => {
                let interim = '';
                let final = '';
                for (let i = event.resultIndex; i < event.results.length; i++) {
                    const words = event.results[i][0]?.transcript || '';
                    if (event.results[i].isFinal) final += words;
                    else interim += words;
                }
                if (final.trim()) this.append(final.trim());
                this.interimSpeech = interim.trim();
            };
            recognition.addEventListener('error', () => this.stopDictation(false));
            recognition.onend = () => {
                if (this.transcribing) {
                    this.commitInterim();
                    this.transcribing = false;
                }
                this.recognition = null;
            };
            this.recognition = recognition;
            try {
                recognition.start();
            } catch {
                this.transcribing = false;
            }
        },

        stopDictation(keep = true) {
            if (keep) this.commitInterim();
            else this.interimSpeech = '';
            this.transcribing = false;
            try {
                this.recognition?.stop();
            } catch {}
            this.recognition = null;
            this.$nextTick(() => this.resize());
        },

        commitInterim() {
            if (this.interimSpeech.trim()) this.append(this.interimSpeech.trim());
            this.interimSpeech = '';
        },

        append(words) {
            const now = (this.$wire.text || '').trim();
            this.$wire.text = now ? `${now} ${words}` : words;
            this.$nextTick(() => this.resize());
        },
    };
}

const register = () => window.Alpine?.data('guideChat', guideChat);
if (window.Alpine) register();
else document.addEventListener('alpine:init', register);
