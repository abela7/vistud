/*
| The tutor's chat on a session page (resources/views/livewire/workspaces/tutor-chat.blade.php,
| App\Livewire\Workspaces\TutorChat). The student's words show at once and the answer streams in (wire:stream);
| the log follows it unless the student has scrolled up. Notes and files from the module can be attached to a
| message (four at most), and a file or picture chosen, pasted or dropped is uploaded first, one at a time,
| into the module's "From the chat" folder (POST /api/v1/files, as the upload dialog does). Words and attachments
| the chat refused come back to the box. Diagrams and formulas in finished replies are drawn by
| resources/js/diagrams.js, loaded only when a reply has one.
*/

import { placeBeside } from './floating.js';
import { toast } from './toasts.js';

const MOST = 4;
const xsrf = () => decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.slice(11) ?? '');
const size = (bytes) => (bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / (1024 * 1024)).toFixed(1).replace(/\.0$/, '')} MB`);

function uploadOne(upload, file) {
    return new Promise((resolve, reject) => {
        const form = new FormData();
        form.append('file', file, file.name);
        form.append('place_type', upload.type);
        form.append('place_id', upload.id);
        form.append('folder', 'From the chat');
        const request = new XMLHttpRequest();
        request.open('POST', upload.url);
        request.setRequestHeader('Accept', 'application/json');
        request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        request.setRequestHeader('X-XSRF-TOKEN', xsrf());
        request.addEventListener('load', () => {
            if (request.status === 201) return resolve(JSON.parse(request.responseText));
            let error = null;
            try {
                error = JSON.parse(request.responseText).error;
            } catch {}
            const fields = error?.details?.fields ?? {};
            reject(new Error({ 413: `${file.name} is bigger than this computer takes (${size(upload.maxBytes)}).`, 419: 'Your session has ended. Reload the page and try again.' }[request.status]
                ?? fields.file?.[0] ?? error?.message ?? `${file.name} couldn't be uploaded. Try it again.`));
        });
        request.addEventListener('error', () => reject(new Error('ViStud couldn\'t be reached. Check the connection and try again.')));
        request.send(form);
    });
}

export function tutorChat({ upload, account, mentionItems = [] }) {
    return {
        draft: '',
        quoteText: '',
        selectedQuoteText: '',
        pending: '',
        pendingQuote: '',
        pendingWords: '',
        pendingFiles: [],
        live: false,
        follow: true,
        hasOverflow: false,
        attached: [],
        mentionItems: Array.isArray(mentionItems) ? mentionItems : [],
        mentionOpen: false,
        mentionQuery: '',
        mentionIndex: -1,
        mentionActiveIndex: 0,
        mentionCategory: 'all',

        get filteredMentions() {
            const q = (this.mentionQuery || '').trim().toLowerCase();
            let list = this.mentionItems || [];
            if (this.mentionCategory === 'notes') {
                list = list.filter((i) => i.kind === 'note');
            } else if (this.mentionCategory === 'files') {
                list = list.filter((i) => i.kind !== 'note');
            }
            if (q !== '') {
                list = list.filter((i) => (i.name || '').toLowerCase().includes(q));
            }
            return list.slice(0, 30);
        },
        picking: false,
        marksDismissed: false,
        transcribing: false,
        speechSupported: false,
        interimSpeech: '',
        recognition: null,
        menu: false,
        quizSub: false,
        toolsStyle: '',
        search: '',
        uploading: 0,
        note: '',
        formatOpen: false,
        turnCount: 0,
        liveBubbleVisible: false,
        revealedChars: 0,
        finishing: false,
        typewriterRaf: null,
        finishTimeout: null,
        streamObserver: null,
        isProgrammaticScroll: false,
        stickRaf: null,
        clearProgrammaticTimer: null,

        init() {
            this.turnCount = this.$refs.log ? this.$refs.log.querySelectorAll('li[data-turn]').length : 0;
            this.initTypewriter();
            this.scrollDown(false);
            this.$nextTick(() => this.scrollDown(false));
            requestAnimationFrame(() => {
                this.scrollDown(false);
                setTimeout(() => this.scrollDown(false), 150);
            });
            this.decorate();
            this.speechSupported = typeof window !== 'undefined' && Boolean(window.SpeechRecognition || window.webkitSpeechRecognition);
            this.$nextTick(() => this.resize());

            this.$watch('live', (val) => {
                if (val) {
                    this.follow = true;
                    this.$nextTick(() => {
                        this.scrollDown(false);
                        requestAnimationFrame(() => this.scrollDown(false));
                    });
                }
            });

            this.$watch('menu', (val) => {
                window.dispatchEvent(new CustomEvent('session-tools-state', { detail: { open: val } }));
                if (val) {
                    this.positionToolsPopover();
                    this.$nextTick(() => this.positionToolsPopover());
                }
            });
            this.$watch('quizSub', () => {
                if (this.menu) {
                    this.$nextTick(() => this.positionToolsPopover());
                }
            });
            window.addEventListener('blur', () => {
                requestAnimationFrame(() => {
                    if (document.activeElement?.tagName === 'IFRAME') {
                        this.menu = false;
                        this.quizSub = false;
                        this.picking = false;
                    }
                });
            });
            this.$watch('draft', (val) => {
                if (!val) {
                    this.$nextTick(() => {
                        if (this.$refs.box) this.$refs.box.style.height = 'auto';
                    });
                }
            });
            if (this.$refs.log) {
                let queued = false;
                new MutationObserver(() => {
                    if (this.follow) {
                        this.scheduleStick();
                    }
                    if (this.live && this.$refs.streamBuffer?.textContent?.trim()) {
                        this.onStreamUpdate();
                    }
                    if (queued) return;
                    queued = true;
                    requestAnimationFrame(() => {
                        queued = false;
                        this.decorate();
                        this.updateOverflow();
                    });
                }).observe(this.$refs.log, { childList: true, subtree: true, characterData: true });

                if (window.ResizeObserver) {
                    new ResizeObserver(() => {
                        if (this.follow) {
                            this.scheduleStick();
                        }
                        this.updateOverflow();
                    }).observe(this.$refs.log);
                }

                this.$refs.log.addEventListener('load', () => {
                    if (this.follow) this.scheduleStick();
                    this.updateOverflow();
                }, true);

                let isMouseDown = false;
                let touchStartY = 0;
                this.$refs.log.addEventListener('mousedown', () => { isMouseDown = true; });
                this.$refs.log.addEventListener('touchstart', (e) => {
                    isMouseDown = true;
                    touchStartY = e.touches[0]?.clientY ?? 0;
                }, { passive: true });
                this.$refs.log.addEventListener('touchmove', (e) => {
                    const currentY = e.touches[0]?.clientY ?? 0;
                    const diff = currentY - touchStartY;
                    if (diff > 12) {
                        const log = this.$refs.log;
                        if (log && (log.scrollHeight - log.scrollTop - log.clientHeight > 30)) {
                            this.follow = false;
                            this.updateOverflow();
                        }
                    } else if (diff < -12) {
                        const log = this.$refs.log;
                        if (log && (log.scrollHeight - log.scrollTop - log.clientHeight <= 80)) {
                            this.follow = true;
                            this.updateOverflow();
                        }
                    }
                }, { passive: true });
                this.$refs.log.addEventListener('scroll', () => { this.hideQuotePill(); }, { passive: true });
                this.$refs.log.addEventListener('keyup', () => {
                    setTimeout(() => this.checkSelection(), 20);
                });
                document.addEventListener('mouseup', () => {
                    if (isMouseDown) {
                        isMouseDown = false;
                        setTimeout(() => this.checkSelection(), 20);
                    }
                });
                document.addEventListener('touchend', () => {
                    if (isMouseDown) {
                        isMouseDown = false;
                        setTimeout(() => this.checkSelection(), 50);
                    }
                });
                document.addEventListener('selectionchange', () => {
                    if (isMouseDown) return;
                    const sel = window.getSelection();
                    if (!sel || sel.isCollapsed) {
                        this.hideQuotePill();
                    }
                });
                document.addEventListener('pointerdown', (e) => {
                    if (this.$refs.quotePill && !this.$refs.quotePill.contains(e.target) && !e.target.closest('[data-chat-quote-pill]')) {
                        this.hideQuotePill();
                    }
                });
            }
            window.addEventListener('vistud:theme-changed', () => {
                if (this.$refs.log?.querySelector('figure.chat-diagram')) import('./diagrams.js').then(({ redrawDiagrams }) => redrawDiagrams(this.$refs.log));
            });
            window.addEventListener('session-layout-changed', () => {
                if (this.follow) this.$nextTick(() => this.stick(false));
                this.updateOverflow();
            });
            window.addEventListener('session-ensure-chat', () => {
                if (this.follow) this.$nextTick(() => this.scrollDown(false));
                this.updateOverflow();
            });
        },

        toggleTools() {
            this.picking = false;
            this.quizSub = false;
            if (!this.menu) {
                this.positionToolsPopover();
                this.menu = true;
            } else {
                this.menu = false;
            }
        },

        positionToolsPopover() {
            const btn = document.querySelector('[data-session-tools-btn]');
            if (!btn) {
                this.toolsStyle = '';
                return;
            }
            const rect = btn.getBoundingClientRect();
            const vw = window.innerWidth || document.documentElement.clientWidth || 1024;
            const vh = window.innerHeight || document.documentElement.clientHeight || 768;
            const edgePad = 8;
            const gap = 6;

            // Detect split-screen panes so the popover respects the pane directly underneath the trigger button
            const btnCenterX = rect.left + rect.width / 2;
            let paneMinX = edgePad;
            let paneMaxX = vw - edgePad;
            const workbench = document.querySelector('.session-workbench.has-split');
            if (workbench && vw >= 768) {
                const panes = [...workbench.querySelectorAll(':scope > .is-pane-first, :scope > .is-pane-second')]
                    .filter((el) => el.offsetWidth > 0);
                const underPane = panes.find((el) => {
                    const r = el.getBoundingClientRect();
                    return btnCenterX >= r.left - 12 && btnCenterX <= r.right + 12;
                });
                if (underPane) {
                    const pr = underPane.getBoundingClientRect();
                    if (pr.width >= 220) {
                        paneMinX = Math.max(edgePad, Math.round(pr.left + 6));
                        paneMaxX = Math.min(vw - edgePad, Math.round(pr.right - 6));
                    }
                }
            }

            // Respect the right-side Topics drawer (.session-rail) if open
            const rail = document.querySelector('.session-rail');
            if (rail && rail.offsetWidth > 0) {
                const rr = rail.getBoundingClientRect();
                if (rr.left > 240 && rr.left < paneMaxX && rect.right <= rr.left + 16) {
                    paneMaxX = Math.min(paneMaxX, Math.round(rr.left - 6));
                }
            }

            const availWidth = Math.max(200, paneMaxX - paneMinX);
            const width = Math.min(308, availWidth, vw - edgePad * 2);

            // Align with the trigger button while staying within [paneMinX, paneMaxX - width]
            let left = Math.round(rect.left);
            if (left + width > paneMaxX) {
                left = paneMaxX - width;
            }
            if (left < paneMinX) {
                left = paneMinX;
            }
            // Guarantee visual tether beneath the button even if a split pane is very narrow
            if (left > rect.right - 36) {
                left = Math.max(edgePad, Math.round(rect.right - width));
            } else if (left + width < rect.left + 36) {
                left = Math.min(vw - width - edgePad, Math.round(rect.left));
            }
            left = Math.max(edgePad, Math.min(left, vw - width - edgePad));

            let top = Math.round(rect.bottom + gap);
            const spaceBelow = vh - top - edgePad;
            const spaceAbove = rect.top - gap - edgePad;
            let maxHeight = Math.max(160, Math.min(420, spaceBelow));
            if (spaceBelow < 200 && spaceAbove > spaceBelow) {
                maxHeight = Math.max(160, Math.min(420, spaceAbove));
                const popHeight = this.$refs.toolsPopover?.offsetHeight || Math.min(320, maxHeight);
                top = Math.max(edgePad, Math.round(rect.top - gap - Math.min(popHeight, maxHeight)));
            }

            this.toolsStyle = `position: fixed; top: ${top}px; left: ${left}px; right: auto; bottom: auto; width: ${width}px; max-height: ${maxHeight}px; z-index: 75;`;
        },

        /** Diagrams and formulas in the finished replies, drawn once each (the drawing code loads only if needed). */
        decorate() {
            const replies = [...(this.$refs.log?.querySelectorAll('li[data-turn] .chat-markdown') ?? [])];
            const waiting = replies.filter((reply) => reply.querySelector('code.language-mermaid:not([data-tried])') || (reply.textContent.includes('$') && reply.dataset.formulas !== 'done'));
            if (waiting.length > 0) {
                import('./diagrams.js').then(({ drawDiagrams }) => {
                    for (const reply of waiting) {
                        reply.dataset.formulas = 'done';
                        drawDiagrams(reply);
                    }
                    if (this.follow) {
                        this.scheduleStick();
                    }
                    this.updateOverflow();
                    this.decorateCodeBlocks(replies);
                });
            }
            this.decorateCodeBlocks(replies);
        },

        decorateCodeBlocks(replies) {
            for (const reply of replies) {
                const pres = reply.querySelectorAll('pre:not([data-copy-ready])');
                for (const pre of pres) {
                    if (pre.querySelector('code.language-mermaid:not([data-tried])')) {
                        continue;
                    }
                    if (pre.closest('.chat-code-block') || pre.closest('.chat-diagram')) {
                        pre.dataset.copyReady = 'true';
                        continue;
                    }

                    pre.dataset.copyReady = 'true';

                    const code = pre.querySelector('code');
                    let lang = 'snippet';
                    if (code) {
                        for (const cls of code.classList) {
                            if (cls.startsWith('language-')) {
                                lang = cls.replace(/^language-/, '').trim() || 'snippet';
                                break;
                            }
                        }
                    }

                    const wrapper = document.createElement('div');
                    wrapper.className = 'chat-code-block';

                    const header = document.createElement('div');
                    header.className = 'chat-code-header';

                    const langBadge = document.createElement('span');
                    langBadge.className = 'chat-code-lang';
                    langBadge.textContent = lang;

                    const copyBtn = document.createElement('button');
                    copyBtn.type = 'button';
                    copyBtn.className = 'chat-code-copy-btn';
                    copyBtn.setAttribute('title', 'Copy to clipboard');
                    copyBtn.setAttribute('aria-label', `Copy ${lang}`);
                    copyBtn.innerHTML = '<svg class="size-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg><span>Copy</span>';

                    copyBtn.addEventListener('click', async (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        const text = (code || pre).textContent || '';
                        let success = false;
                        try {
                            if (navigator.clipboard?.writeText) {
                                await navigator.clipboard.writeText(text);
                                success = true;
                            }
                        } catch {}
                        if (!success) {
                            try {
                                const ta = document.createElement('textarea');
                                ta.value = text;
                                ta.style.position = 'fixed';
                                ta.style.opacity = '0';
                                ta.style.left = '-9999px';
                                document.body.appendChild(ta);
                                ta.select();
                                success = document.execCommand('copy');
                                document.body.removeChild(ta);
                            } catch {}
                        }

                        copyBtn.classList.add('is-copied');
                        copyBtn.innerHTML = '<svg class="size-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg><span>Copied!</span>';
                        setTimeout(() => {
                            copyBtn.classList.remove('is-copied');
                            copyBtn.innerHTML = '<svg class="size-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg><span>Copy</span>';
                        }, 2000);
                    });

                    header.appendChild(langBadge);
                    header.appendChild(copyBtn);

                    pre.parentNode?.insertBefore(wrapper, pre);
                    wrapper.appendChild(header);
                    wrapper.appendChild(pre);
                }
            }
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
            const val = el.value || '';
            const selected = val.substring(start, end) || placeholder;
            const replacement = before + selected + after;
            el.value = val.substring(0, start) + replacement + val.substring(end);
            this.draft = el.value;
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
            this.draft = el.value;
            el.focus();
            const newPos = lineStart + replacement.length;
            el.setSelectionRange(newPos, newPos);
            this.$nextTick(() => this.resize());
        },

        handleKeydown(event) {
            const el = this.$refs.box;
            if (!el) return;

            if (this.mentionOpen) {
                const list = this.filteredMentions;
                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    event.stopPropagation();
                    if (list.length > 0) {
                        this.mentionActiveIndex = (this.mentionActiveIndex + 1) % list.length;
                        this.scrollActiveMentionIntoView();
                    }
                    return;
                }
                if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    event.stopPropagation();
                    if (list.length > 0) {
                        this.mentionActiveIndex = (this.mentionActiveIndex - 1 + list.length) % list.length;
                        this.scrollActiveMentionIntoView();
                    }
                    return;
                }
                if (event.key === 'Enter' || event.key === 'Tab') {
                    if (list.length > 0) {
                        event.preventDefault();
                        event.stopPropagation();
                        const selected = list[this.mentionActiveIndex];
                        if (selected) {
                            this.selectMention(selected);
                            return;
                        }
                    }
                    this.mentionOpen = false;
                }
                if (event.key === 'Escape') {
                    event.preventDefault();
                    event.stopPropagation();
                    this.mentionOpen = false;
                    this.mentionQuery = '';
                    this.mentionIndex = -1;
                    return;
                }
            }

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
                    const [, indent, marker, lineText] = listMatch;
                    if (lineText.trim() === '') {
                        event.preventDefault();
                        el.value = val.slice(0, lineStart) + val.slice(lineEnd);
                        el.setSelectionRange(lineStart, lineStart);
                        this.draft = el.value;
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
                        this.draft = el.value;
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

        updateOverflow() {
            const log = this.$refs.log;
            if (!log) {
                this.hasOverflow = false;
                return;
            }
            this.hasOverflow = log.scrollHeight > (log.clientHeight + 40);
        },

        onScroll() {
            if (this.isProgrammaticScroll) return;
            const log = this.$refs.log;
            if (!log) return;
            const fromBottom = log.scrollHeight - log.scrollTop - log.clientHeight;
            if (fromBottom <= 80) {
                this.follow = true;
            } else if (fromBottom > 100) {
                this.follow = false;
            }
            this.updateOverflow();
        },

        onWheel(event) {
            const log = this.$refs.log;
            if (!log) return;
            if (event.deltaY < 0) {
                const fromBottom = log.scrollHeight - log.scrollTop - log.clientHeight;
                if (fromBottom > 30) {
                    this.follow = false;
                }
            } else if (event.deltaY > 0) {
                const fromBottom = log.scrollHeight - log.scrollTop - log.clientHeight;
                if (fromBottom <= 80) {
                    this.follow = true;
                }
            }
            this.updateOverflow();
        },

        scheduleStick() {
            if (!this.follow || !this.$refs.log) return;
            if (this.stickRaf) return;
            this.stickRaf = requestAnimationFrame(() => {
                this.stickRaf = null;
                this.stick(false);
            });
        },

        stick(smooth = false) {
            const log = this.$refs.log;
            if (!log || !this.follow) return;
            this.isProgrammaticScroll = true;
            if (smooth) {
                log.scrollTo({ top: log.scrollHeight, behavior: 'smooth' });
            } else {
                log.scrollTop = log.scrollHeight;
            }
            if (this.clearProgrammaticTimer) {
                clearTimeout(this.clearProgrammaticTimer);
            }
            this.clearProgrammaticTimer = setTimeout(() => {
                this.isProgrammaticScroll = false;
                this.updateOverflow();
            }, 120);
        },

        scrollDown(smooth = false) {
            this.follow = true;
            const log = this.$refs.log;
            if (!log) return;
            this.isProgrammaticScroll = true;
            if (smooth) {
                log.scrollTo({ top: log.scrollHeight, behavior: 'smooth' });
            } else {
                log.scrollTop = log.scrollHeight;
            }
            if (this.clearProgrammaticTimer) {
                clearTimeout(this.clearProgrammaticTimer);
            }
            this.clearProgrammaticTimer = setTimeout(() => {
                this.isProgrammaticScroll = false;
                this.updateOverflow();
            }, 200);
        },

        get busy() {
            return this.live || this.uploading > 0;
        },

        checkSelection() {
            const pill = this.$refs.quotePill;
            if (!pill) return;

            const sel = window.getSelection();
            if (!sel || sel.isCollapsed || sel.rangeCount === 0) {
                this.hideQuotePill();
                return;
            }

            const text = sel.toString().trim();
            if (text.length < 2) {
                this.hideQuotePill();
                return;
            }

            const range = sel.getRangeAt(0);
            const container = range.commonAncestorContainer;
            const log = this.$refs.log;
            if (!log || !log.contains(container)) {
                this.hideQuotePill();
                return;
            }

            const bubble = container.nodeType === Node.ELEMENT_NODE
                ? container.closest('.chat-bubble, [data-turn]')
                : container.parentElement?.closest('.chat-bubble, [data-turn]');

            if (!bubble) {
                this.hideQuotePill();
                return;
            }

            const rect = range.getBoundingClientRect();
            if (!rect || (rect.width === 0 && rect.height === 0)) {
                this.hideQuotePill();
                return;
            }

            this.showQuotePill(rect, text);
        },

        showQuotePill(rect, text) {
            const pill = this.$refs.quotePill;
            if (!pill) return;
            this.selectedQuoteText = text;
            pill.hidden = false;
            try {
                if (!pill.matches(':popover-open')) pill.showPopover();
            } catch {}
            placeBeside(rect, pill, { align: 'end', prefer: 'above' });
        },

        hideQuotePill() {
            const pill = this.$refs.quotePill;
            if (!pill) return;
            try {
                if (pill.matches(':popover-open')) pill.hidePopover();
            } catch {}
            pill.hidden = true;
        },

        replyToSelection() {
            if (!this.selectedQuoteText) return;
            this.quoteText = this.selectedQuoteText.trim();
            this.hideQuotePill();
            try {
                window.getSelection()?.removeAllRanges();
            } catch {}
            this.$nextTick(() => {
                if (this.$refs.box) {
                    this.$refs.box.focus();
                    this.resize();
                }
            });
        },

        replyToTurn(text) {
            if (!text) return;
            this.quoteText = text.trim();
            this.hideQuotePill();
            this.$nextTick(() => {
                if (this.$refs.box) {
                    this.$refs.box.focus();
                    this.resize();
                }
            });
        },

        clearQuote() {
            this.quoteText = '';
            this.$nextTick(() => {
                if (this.$refs.box) {
                    this.$refs.box.focus();
                    this.resize();
                }
            });
        },

        initTypewriter() {
            if (this.$refs.streamBuffer) {
                this.streamObserver = new MutationObserver(() => this.onStreamUpdate());
                this.streamObserver.observe(this.$refs.streamBuffer, { childList: true, subtree: true, characterData: true });
            }
            if (window.Livewire?.hook) {
                window.Livewire.hook('stream', (json) => {
                    if (json?.name === 'answer') {
                        this.$nextTick(() => this.onStreamUpdate());
                    }
                });
            }
        },

        onStreamUpdate() {
            const buffer = this.$refs.streamBuffer;
            if (!buffer) return;
            const text = (buffer.textContent || '').trim();
            if (text === '' && buffer.children.length === 0) {
                return;
            }
            this.liveBubbleVisible = true;
            if (!this.typewriterRaf) {
                this.startTypewriter();
            }
        },

        startTypewriter() {
            if (this.typewriterRaf) return;
            const tick = () => {
                this.typewriterStep();
                if (this.live && this.liveBubbleVisible) {
                    this.typewriterRaf = requestAnimationFrame(tick);
                } else {
                    this.typewriterRaf = null;
                }
            };
            this.typewriterRaf = requestAnimationFrame(tick);
        },

        typewriterStep() {
            const buffer = this.$refs.streamBuffer;
            const bubble = this.$refs.liveBubble;
            if (!buffer || !bubble) return;

            const targetChars = buffer.textContent.length;
            const diff = targetChars - this.revealedChars;

            if (diff > 0) {
                let step = 1;
                if (this.finishing) {
                    step = Math.max(8, Math.ceil(diff / 6));
                } else if (diff > 120) {
                    step = Math.ceil(diff / 10);
                } else if (diff > 50) {
                    step = 4;
                } else if (diff > 20) {
                    step = 2;
                } else {
                    step = 1;
                }

                this.revealedChars = Math.min(targetChars, this.revealedChars + step);
                this.renderLivePartial();

                if (this.follow) {
                    this.scheduleStick();
                }
            } else if (this.finishing) {
                this.renderLivePartial();
                this.completeTurn();
            }
        },

        renderLivePartial() {
            const buffer = this.$refs.streamBuffer;
            const bubble = this.$refs.liveBubble;
            if (!buffer || !bubble) return;

            const state = { remaining: this.revealedChars };
            const fragment = document.createDocumentFragment();

            for (const child of buffer.childNodes) {
                if (state.remaining <= 0) break;
                const cloned = this.cloneTreeUpTo(child, state);
                if (cloned) {
                    fragment.appendChild(cloned);
                }
            }

            // Append cursor at the tip of the stream
            const cursor = document.createElement('span');
            cursor.className = 'chat-live-cursor';
            cursor.setAttribute('aria-hidden', 'true');
            this.insertCursorAtTip(fragment, cursor);

            bubble.replaceChildren(fragment);
        },

        cloneTreeUpTo(node, state) {
            if (state.remaining <= 0) return null;

            if (node.nodeType === Node.TEXT_NODE) {
                const text = node.textContent;
                if (text.length <= state.remaining) {
                    state.remaining -= text.length;
                    return document.createTextNode(text);
                } else {
                    const slice = text.slice(0, state.remaining);
                    state.remaining = 0;
                    return document.createTextNode(slice);
                }
            }

            if (node.nodeType === Node.ELEMENT_NODE) {
                if (['BR', 'HR', 'IMG'].includes(node.tagName)) {
                    return node.cloneNode(true);
                }
                const clone = node.cloneNode(false);
                for (const child of node.childNodes) {
                    if (state.remaining <= 0) break;
                    const childClone = this.cloneTreeUpTo(child, state);
                    if (childClone) {
                        clone.appendChild(childClone);
                    }
                }
                if (clone.childNodes.length === 0) {
                    return null;
                }
                return clone;
            }

            return null;
        },

        insertCursorAtTip(container, cursor) {
            if (!container.lastChild) {
                container.appendChild(cursor);
                return;
            }
            let target = container.lastChild;
            while (target && target.nodeType === Node.ELEMENT_NODE && target.lastChild) {
                target = target.lastChild;
            }
            if (target && target.nodeType === Node.TEXT_NODE && target.parentNode) {
                target.parentNode.appendChild(cursor);
            } else if (target && target.nodeType === Node.ELEMENT_NODE && !['TABLE', 'THEAD', 'TBODY', 'TR', 'UL', 'OL'].includes(target.tagName)) {
                target.appendChild(cursor);
            } else {
                container.appendChild(cursor);
            }
        },

        resetTypewriter() {
            this.cancelTypewriter();
            this.revealedChars = 0;
            this.finishing = false;
            this.liveBubbleVisible = false;
            if (this.$refs.streamBuffer) this.$refs.streamBuffer.innerHTML = '';
            if (this.$refs.liveBubble) this.$refs.liveBubble.innerHTML = '';
        },

        cancelTypewriter() {
            if (this.typewriterRaf) {
                cancelAnimationFrame(this.typewriterRaf);
                this.typewriterRaf = null;
            }
            if (this.finishTimeout) {
                clearTimeout(this.finishTimeout);
                this.finishTimeout = null;
            }
        },

        completeTurn() {
            this.cancelTypewriter();
            this.finishing = false;
            this.live = false;
            this.pending = '';
            this.pendingQuote = '';
            this.pendingWords = '';
            this.pendingFiles = [];
            this.revealedChars = 0;
            this.liveBubbleVisible = false;
            this.marksDismissed = false;
            if (this.$refs.streamBuffer) this.$refs.streamBuffer.innerHTML = '';
            if (this.$refs.liveBubble) this.$refs.liveBubble.innerHTML = '';
            this.turnCount = this.$refs.log ? this.$refs.log.querySelectorAll('li[data-turn]').length : 0;
            this.$nextTick(() => {
                if (this.follow) {
                    this.scrollDown(false);
                }
                this.decorate();
                this.resize();
                this.$refs.box?.focus();
            });
        },

        submit() {
            this.stopDictation();
            let words = this.draft.trim();
            const hasQuote = this.quoteText.trim() !== '';
            if ((words === '' && !hasQuote && this.attached.length === 0) || this.busy) return;

            let fullWords = words;
            let quotedExcerpt = '';
            if (hasQuote) {
                quotedExcerpt = this.quoteText.trim();
                const quoteBlock = quotedExcerpt.split('\n').map((line) => `> ${line}`).join('\n');
                fullWords = words !== '' ? `${quoteBlock}\n\n${words}` : quoteBlock;
            }

            const refs = this.attached.map((item) => item.ref);
            [this.pending, this.pendingQuote, this.pendingWords, this.pendingFiles, this.draft, this.quoteText, this.attached] = [
                fullWords,
                quotedExcerpt,
                words,
                this.attached,
                '',
                '',
                []
            ];
            [this.live, this.follow, this.picking, this.note] = [true, true, false, ''];
            this.mentionOpen = false;
            this.mentionQuery = '';
            this.turnCount = this.$refs.log ? this.$refs.log.querySelectorAll('li[data-turn]').length : 0;
            this.resetTypewriter();
            if (this.$refs.box) this.$refs.box.style.height = 'auto';
            this.scrollDown(false);
            this.$nextTick(() => this.scrollDown(false));
            this.$wire.send(fullWords, refs);
        },

        say(words) {
            this.stopDictation();
            if (this.busy) return;
            window.dispatchEvent(new CustomEvent('session-ensure-chat'));
            [this.pending, this.pendingQuote, this.pendingWords, this.live, this.follow] = [words, '', words, true, true];
            this.turnCount = this.$refs.log ? this.$refs.log.querySelectorAll('li[data-turn]').length : 0;
            this.resetTypewriter();
            this.scrollDown(false);
            this.$nextTick(() => this.scrollDown(false));
            this.$wire.say(words);
        },

        retry() {
            this.stopDictation();
            if (this.busy) return;
            [this.live, this.follow] = [true, true];
            this.turnCount = this.$refs.log ? this.$refs.log.querySelectorAll('li[data-turn]').length : 0;
            this.resetTypewriter();
            this.scrollDown(false);
            this.$nextTick(() => this.scrollDown(false));
            this.$wire.retry();
        },

        /** The turn is over. A refused one gives its words and attachments back to the box. */
        done(restore) {
            if (restore !== null && restore !== undefined) {
                this.draft = restore;
                this.attached = this.pendingFiles;
                this.completeTurn();
                return;
            }

            const buffer = this.$refs.streamBuffer;
            const targetChars = buffer ? buffer.textContent.length : 0;
            if (targetChars > this.revealedChars) {
                this.finishing = true;
                if (this.finishTimeout) clearTimeout(this.finishTimeout);
                this.finishTimeout = setTimeout(() => {
                    this.completeTurn();
                }, 900);
                if (!this.typewriterRaf) {
                    this.startTypewriter();
                }
            } else {
                this.completeTurn();
            }
        },

        /**
         * The tutor wrote in notes: an editor open on one (in another window or tab) is told the way its own
         * tabs tell each other, and shows the new version unless the student is typing in it.
         */
        noteChanged(notes) {
            if (!('BroadcastChannel' in window) || !Array.isArray(notes)) return;
            const channel = new BroadcastChannel(`vistud-${account}`);
            for (const note of notes) channel.postMessage({ type: 'note-saved', note: note.id, version: note.version, client: 'tutor' });
            channel.close();
        },

        has(ref) {
            return this.attached.some((item) => item.ref === ref);
        },

        toggle(item) {
            if (this.has(item.ref)) return this.remove(item.ref);
            if (this.attached.length >= MOST) return (this.note = `Attach up to ${MOST} at a time.`);
            const thumbUrl = item.thumbUrl || (item.kind === 'picture' && item.ref?.startsWith('file:') ? `/files/${item.ref.replace('file:', '')}/content` : null);
            this.attached.push({ ...item, thumbUrl });
            this.note = '';
            this.$nextTick(() => this.resize());
        },

        remove(ref) {
            const item = this.attached.find((i) => i.ref === ref);
            if (item?.thumbUrl?.startsWith('blob:')) {
                try { URL.revokeObjectURL(item.thumbUrl); } catch {}
            }
            this.attached = this.attached.filter((item) => item.ref !== ref);
            this.note = '';
            this.$nextTick(() => this.resize());
        },

        matches(name) {
            return name.toLowerCase().includes(this.search.trim().toLowerCase());
        },

        icon(kind) {
            return upload.icons[kind] ?? upload.icons.file;
        },

        /** Files chosen, pasted or dropped: each uploaded into the module, then attached. */
        async add(files) {
            for (const file of files) {
                if (this.attached.length + this.uploading >= MOST) {
                    this.note = `Attach up to ${MOST} at a time.`;
                    break;
                }
                if (file.size > upload.maxBytes) {
                    this.note = `${file.name} is bigger than ${size(upload.maxBytes)}.`;
                    continue;
                }
                const isPicture = file.type.startsWith('image/');
                const localThumb = isPicture ? URL.createObjectURL(file) : null;
                this.uploading++;
                try {
                    const made = await uploadOne(upload, file);
                    const serverThumb = isPicture || made.kind === 'image' ? (made.contentUrl || `/files/${made.id}/content`) : null;
                    const newItem = {
                        ref: `file:${made.id}`,
                        name: made.name,
                        kind: isPicture || made.kind === 'image' ? 'picture' : 'file',
                        thumbUrl: localThumb || serverThumb,
                        ext: made.extension || (made.name.includes('.') ? made.name.split('.').pop() : null),
                        inScope: true,
                    };
                    this.attached.push(newItem);
                    if (!this.mentionItems.some((item) => item.ref === newItem.ref)) {
                        this.mentionItems.unshift(newItem);
                    }
                    this.note = '';
                } catch (error) {
                    if (localThumb) {
                        try { URL.revokeObjectURL(localThumb); } catch {}
                    }
                    this.note = error.message;
                } finally {
                    this.uploading--;
                }
            }
            this.picking = false;
            this.$refs.box?.focus();
            this.$nextTick(() => this.resize());
        },

        commitInterim() {
            if (this.interimSpeech && this.interimSpeech.trim()) {
                const chunk = this.interimSpeech.trim();
                const current = this.draft.trim();
                this.draft = current ? `${current} ${chunk}` : chunk;
                this.interimSpeech = '';
                this.$nextTick(() => this.resize());
            }
        },

        startDictation() {
            if (this.busy) return;
            const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            if (!SpeechRecognition) {
                toast('Live speech transcription is not supported in this browser.', 'warning');
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
                        const current = this.draft.trim();
                        this.draft = current ? `${current} ${chunk}` : chunk;
                        this.$nextTick(() => this.resize());
                    }

                    this.interimSpeech = interim.trim();
                };

                recognition.onerror = (event) => {
                    if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
                        toast('Microphone access was denied. Please allow microphone permissions.', 'warning');
                        this.stopDictation(false);
                    } else if (event.error === 'network') {
                        toast('Network issue with speech recognition service.', 'warning');
                        this.stopDictation(false);
                    }
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
                console.error('Failed to start speech recognition:', err);
                toast('Could not start speech recognition.', 'warning');
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
                try {
                    this.recognition.stop();
                } catch {}
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

        toggleDictation() {
            if (this.transcribing) {
                this.stopDictation(true);
            } else {
                this.startDictation();
            }
        },

        chosen(event) {
            const files = [...event.target.files];
            event.target.value = '';
            this.add(files);
        },

        handleInput(event) {
            this.resize();
            this.checkMentionTrigger();
        },

        checkMentionTrigger() {
            const el = this.$refs.box;
            if (!el) return;
            const val = el.value || '';
            const caret = el.selectionStart ?? val.length;
            const beforeCaret = val.slice(0, caret);

            const lastAt = beforeCaret.lastIndexOf('@');
            if (lastAt === -1) {
                if (this.mentionOpen) {
                    this.mentionOpen = false;
                    this.mentionQuery = '';
                    this.mentionIndex = -1;
                }
                return;
            }

            // Check character preceding @ to avoid triggering in the middle of words or emails
            if (lastAt > 0) {
                const charBefore = beforeCaret[lastAt - 1];
                if (!/[\s\n(]/.test(charBefore)) {
                    if (this.mentionOpen) {
                        this.mentionOpen = false;
                        this.mentionQuery = '';
                        this.mentionIndex = -1;
                    }
                    return;
                }
            }

            const query = beforeCaret.slice(lastAt + 1);
            // If the query starts with space, e.g. "@ 5pm", has newlines, is too long, or has multiple words:
            if (query.startsWith(' ') || query.includes('\n') || query.length > 25 || (query.match(/\s/g) || []).length > 1) {
                if (this.mentionOpen) {
                    this.mentionOpen = false;
                    this.mentionQuery = '';
                    this.mentionIndex = -1;
                }
                return;
            }

            this.mentionOpen = true;
            this.mentionQuery = query;
            this.mentionIndex = lastAt;
            if (this.mentionActiveIndex >= this.filteredMentions.length) {
                this.mentionActiveIndex = 0;
            }
            this.scrollActiveMentionIntoView();
        },

        openMentionPicker() {
            if (this.mentionOpen) {
                this.mentionOpen = false;
                this.mentionQuery = '';
                this.mentionIndex = -1;
                return;
            }
            const el = this.$refs.box;
            if (el) el.focus();
            this.mentionOpen = true;
            this.mentionQuery = '';
            this.mentionIndex = -1;
            this.mentionActiveIndex = 0;
            this.$nextTick(() => {
                this.scrollActiveMentionIntoView();
            });
        },

        selectMention(item) {
            if (!item) return;
            const el = this.$refs.box;
            if (el) {
                const val = el.value || '';
                // If mention was triggered by '@' in the textarea, remove the '@query' chunk so the input stays clean
                if (this.mentionIndex >= 0 && this.mentionIndex < val.length) {
                    const start = this.mentionIndex;
                    const end = Math.max(start + 1, el.selectionStart ?? val.length);
                    const before = val.slice(0, start);
                    const after = val.slice(end);

                    let cleanBefore = before;
                    let cleanAfter = after;
                    // Avoid leaving duplicate spaces if before and after both had space
                    if (cleanBefore.endsWith(' ') && cleanAfter.startsWith(' ')) {
                        cleanAfter = cleanAfter.trimStart();
                    }
                    el.value = cleanBefore + cleanAfter;
                    const newCaret = cleanBefore.length;
                    this.draft = el.value;
                    this.$nextTick(() => {
                        el.focus();
                        el.setSelectionRange(newCaret, newCaret);
                        this.resize();
                    });
                } else {
                    this.$nextTick(() => {
                        el.focus();
                        this.resize();
                    });
                }
            }

            // Attach the chosen note or file as an attachment chip above the dock
            if (!this.has(item.ref) && this.attached.length < MOST) {
                this.toggle({
                    ref: item.ref,
                    name: item.name,
                    kind: item.kind,
                    thumbUrl: item.thumbUrl ?? null,
                });
            }

            // Reset and close mention popover
            this.mentionOpen = false;
            this.mentionQuery = '';
            this.mentionIndex = -1;
            this.mentionActiveIndex = 0;
        },

        scrollActiveMentionIntoView() {
            this.$nextTick(() => {
                const menu = this.$refs.mentionMenu;
                if (!menu) return;
                const activeEl = menu.querySelector('.chat-mention-item.is-active');
                if (activeEl) {
                    activeEl.scrollIntoView({ block: 'nearest' });
                }
            });
        },

        /** A screenshot or file pasted into the box is uploaded and attached; pasted words stay words. */
        paste(event) {
            const files = [...(event.clipboardData?.files ?? [])];
            if (files.length === 0) return;
            event.preventDefault();
            const stamp = new Date().toISOString().slice(0, 16).replace('T', ' ').replace(':', '.');
            const prepared = files.map((file, i) => {
                if (file.type.startsWith('image/')) {
                    return new File([file], `Pasted picture ${stamp}${files.length > 1 ? ` (${i + 1})` : ''}.${(file.type.split('/')[1] || 'png').replace('jpeg', 'jpg')}`, { type: file.type });
                }

                return file;
            });
            this.add(prepared);
        },

        drop(event) {
            const files = [...(event.dataTransfer?.files ?? [])];
            if (files.length > 0) this.add(files);
        },
    };
}

const register = () => window.Alpine?.data('tutorChat', tutorChat);
if (window.Alpine) register();
else document.addEventListener('alpine:init', register);
