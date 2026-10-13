/*
| What the AI's replies get once they are on the page, in every chat (the tutor's, resources/js/tutor-chat.js, and the
| course guide's, resources/js/guide-chat.js): code blocks with their language and a Copy button, and diagrams and
| formulas drawn (the drawing code loads only when a reply has one).
*/
/** Code blocks in the replies get a header with their language and a Copy button, once each (a diagram's block is left for drawDiagrams). */
export function decorateCodeBlocks(replies) {
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
}

/** Diagrams and formulas in the replies not drawn yet, then their code blocks. Resolves once all are done. */
export function decorateReplies(replies) {
    const waiting = replies.filter((reply) => reply.querySelector('code.language-mermaid:not([data-tried])') || (reply.textContent.includes('$') && reply.dataset.formulas !== 'done'));
    decorateCodeBlocks(replies);
    if (waiting.length === 0) return Promise.resolve();

    return import('./diagrams.js').then(({ drawDiagrams }) => {
        for (const reply of waiting) {
            reply.dataset.formulas = 'done';
            drawDiagrams(reply);
        }
        decorateCodeBlocks(replies);
    });
}
