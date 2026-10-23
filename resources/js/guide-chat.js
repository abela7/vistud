/*
| The course guide's talk (App\Livewire\Workspaces\GuideChat), in the tutor chat's way: the student's words show at once,
| the guide's answer streams in as it is written (wire:stream into "guide-answer"), the log follows the newest line
| unless the student has scrolled up, and finished replies get their code blocks, formulas and diagrams
| (resources/js/chat-markdown.js). The box writes Markdown with shortcuts (Ctrl+B, Ctrl+I, Ctrl+E), continues lists,
| takes a pasted or dropped screenshot, and can be dictated into.
*/

import { hydrateBlocks } from './chat-blocks.js';
import { decorateReplies } from './chat-markdown.js';
import { joinSpeech } from './dictation.js';
import { record, recordingSupported, writeDown } from './recorder.js';
import { toast } from './toasts.js';

/** Whether this browser starts the guide in full screen, or has been asked not to suggest it. */
const FULLSCREEN = 'vistud.guide-fullscreen';

export function guideChat() {
    return {
        formatOpen: false,
        pending: '',
        pendingPictures: 0,
        pendingFiles: 0,
        live: false,
        follow: true,
        fullscreen: false,
        offerFullscreen: false,
        speechSupported: false,
        transcribing: false,
        hearing: false,
        interimSpeech: '',
        dictationStart: '',
        dictationBase: '',
        dictationSeconds: 0,
        dictationTimer: null,
        stopListening: null,
        // The recording under way (resources/js/recorder.js), and whether its words are being written down.
        recording: null,
        writingDown: false,

        get dictationTime() {
            return `${Math.floor(this.dictationSeconds / 60)}:${String(this.dictationSeconds % 60).padStart(2, '0')}`;
        },

        init() {
            this.speechSupported = recordingSupported() && Boolean(this.$root.dataset.dictationUrl);
            // The UI skill's blocks: a tapped choice is sent as the student's words.
            hydrateBlocks(this.$root, { choose: (words) => { this.$wire.text = words; this.submit(); } });
            const pref = storedFullscreen();
            if (pref === 'on') this.fullscreen = true;
            this.offerFullscreen = this.$root.dataset.fresh === '1' && pref !== 'on' && pref !== 'off';
            const frame = (on) => {
                document.documentElement.toggleAttribute('data-session-fullscreen', on);
                document.documentElement.toggleAttribute('data-guide-fullscreen', on);
            };
            frame(this.fullscreen);
            this.$watch('fullscreen', (on) => {
                frame(on);
                try {
                    if (on) localStorage.setItem(FULLSCREEN, 'on');
                    else if (localStorage.getItem(FULLSCREEN) === 'on') localStorage.removeItem(FULLSCREEN);
                } catch {}
                this.$nextTick(() => this.measure());
            });
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

        /** The opening offer: full screen for this talk, and the next ones, until they leave it. */
        useFullscreen() {
            this.offerFullscreen = false;
            this.fullscreen = true;
        },

        /** Leaves the talk as it is, and doesn't ask again in this browser. */
        dismissFullscreen() {
            this.offerFullscreen = false;
            try { localStorage.setItem(FULLSCREEN, 'off'); } catch {}
        },

        destroy() {
            document.documentElement.removeAttribute('data-session-fullscreen');
            document.documentElement.removeAttribute('data-guide-fullscreen');
            this.stopListening?.();
            this.recording?.cancel();
            clearInterval(this.dictationTimer);
        },

        /** Sends what is in the box: shown at once, then the guide's answer as it streams in. */
        submit() {
            if (this.live || this.writingDown) return;
            // While recording, Enter first writes down what was said; the student sends it after reading it.
            if (this.transcribing) return this.stopDictation(true);
            const words = (this.$wire.text || '').trim();
            this.pending = words;
            const waiting = [...this.$root.querySelectorAll('.guide-pictures > li')];
            this.pendingPictures = waiting.filter((item) => item.dataset.kind === 'picture').length;
            this.pendingFiles = waiting.length - this.pendingPictures;
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
            this.pendingFiles = 0;
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

        onInput(event) {
            if (this.transcribing) [this.dictationBase, this.interimSpeech] = [event.target.value, ''];
            this.resize();
        },

        /** A screenshot, or a Word, PDF or text file, pasted or dropped on the box goes with the next message. */
        take(event) {
            const files = [...((event.clipboardData ?? event.dataTransfer)?.files ?? [])].filter((file) => file.type.startsWith('image/') || /\.(pdf|docx|pptx|xlsx|txt|md)$/i.test(file.name));
            if (files.length > 0) {
                event.preventDefault();
                this.$wire.uploadMultiple('pictures', files);
            }
        },

        /**
         * Starts voice typing, as the tutor's chat does (resources/js/recorder.js): it records until the student stops,
         * then the words are written down by the model chosen in their AI settings and put in the box.
         */
        async startDictation() {
            if (this.live || this.transcribing || this.writingDown) return;
            const url = this.$root.dataset.dictationUrl;
            if (!recordingSupported() || !url) {
                toast('Voice typing is not available in this browser. Try Chrome, Edge or Safari.', 'warning');
                return;
            }
            try {
                this.recording = await record();
            } catch {
                toast('ViStud can\'t hear you. Allow the microphone for this site, then try again.', 'warning');
                return;
            }
            const now = this.$refs.box?.value || this.$wire.text || '';
            [this.dictationStart, this.dictationBase, this.interimSpeech] = [now, now, ''];
            [this.transcribing, this.hearing, this.dictationSeconds] = [true, true, 0];
            clearInterval(this.dictationTimer);
            this.dictationTimer = setInterval(() => {
                this.dictationSeconds += 1;
                // Five minutes at most: past that it is written down by itself.
                if (this.dictationSeconds >= 295) this.stopDictation(true);
            }, 1000);
            this.$nextTick(() => this.focusBoxEnd());
        },

        /** The box: the text kept so far, then the phrase still being heard. */
        showSpeech(sure, stillHearing) {
            if (!this.transcribing) return;
            if (sure !== '') this.dictationBase = joinSpeech(this.dictationBase, sure);
            this.interimSpeech = stillHearing;
            this.writeBox(joinSpeech(this.dictationBase, stillHearing));
        },

        writeBox(words) {
            this.$wire.text = words;
            const box = this.$refs.box;
            if (box && box.value !== words) box.value = words;
            this.$nextTick(() => {
                this.resize();
                if (box) box.scrollTop = box.scrollHeight;
            });
        },

        /**
         * Stops voice typing: the recording is written down and its words go after what is in the box (or, with
         * keep false, it is thrown away and the box is as it was).
         */
        async stopDictation(keep = true) {
            const stop = this.stopListening;
            this.stopListening = null;
            stop?.();
            clearInterval(this.dictationTimer);
            this.dictationTimer = null;
            if (!this.transcribing) return;
            const recording = this.recording;
            this.recording = null;
            [this.transcribing, this.hearing, this.interimSpeech] = [false, false, ''];
            if (!keep || !recording) {
                recording?.cancel();
                this.writeBox(this.dictationStart);
            } else {
                this.writingDown = true;
                try {
                    const words = await writeDown(this.$root.dataset.dictationUrl, await recording.stop());
                    if (words === '') toast('Nothing was heard. Try again a little closer to the microphone.', 'warning');
                    this.writeBox(joinSpeech(this.$refs.box?.value || this.$wire.text || '', words));
                } catch (error) {
                    toast(error.message, 'warning');
                } finally {
                    this.writingDown = false;
                }
            }
            this.$nextTick(() => this.focusBoxEnd());
        },

        toggleDictation() {
            if (this.transcribing) this.stopDictation(true);
            else this.startDictation();
        },

        /**
         * Alt+S starts or stops voice typing. While speaking, Enter sends and Esc stops.
         * Esc with nothing being said leaves full screen.
         */
        onKey(event) {
            if (event.isComposing) return;
            if (event.code === 'KeyS' && event.altKey && !event.ctrlKey && !event.metaKey && !event.shiftKey) {
                event.preventDefault();
                if (!event.repeat) this.toggleDictation();
                return;
            }
            if (this.transcribing && event.key === 'Escape') {
                const target = event.target;
                if (target !== this.$refs.box && target?.closest?.('input, textarea, select, [contenteditable="true"]')) return;
                event.preventDefault();
                this.stopDictation(true);
                return;
            }
            if (event.key === 'Escape' && this.fullscreen && !this.transcribing) {
                this.fullscreen = false;
            }
        },

        focusBoxEnd() {
            const box = this.$refs.box;
            if (!box || box.disabled) return;
            box.focus();
            const end = box.value.length;
            box.setSelectionRange(end, end);
            this.resize();
        },
    };
}

function storedFullscreen() {
    try {
        return localStorage.getItem(FULLSCREEN);
    } catch {
        return null;
    }
}

const register = () => window.Alpine?.data('guideChat', guideChat);
if (window.Alpine) register();
else document.addEventListener('alpine:init', register);
