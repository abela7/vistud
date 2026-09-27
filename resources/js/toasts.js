/*
| Toasts (DESIGN.md §5.3): a passing notice at the bottom ("The question is
| deleted.") that closes itself after a few seconds, or with its × sooner,
| and waits while the pointer (once it moves there) or keyboard focus is on
| it. The stack is one polite live region, so screen readers hear each one
| once.
|
| Livewire components and pages render <x-toast>: a hidden [data-toast] with
| a fresh data-toast-id each time the notice is new. Each ID shows once.
| A page's own scripts call toast(text, tone).
*/

const LIFE = 6000;
const MOST = 3;
const seen = new Set();

export function toast(text, tone = 'success') {
    const stack = document.querySelector('[data-toasts]');
    const template = document.querySelector('template[data-toast-template]');
    if (!stack || !template || !text) return;

    const item = template.content.firstElementChild.cloneNode(true);
    item.dataset.tone = tone;
    item.querySelectorAll('[data-toast-icon]').forEach((icon) => { icon.hidden = icon.dataset.toastIcon !== tone; });
    item.querySelector('[data-toast-text]').textContent = text;

    let timer = null;
    let left = LIFE;
    let since = 0;
    let held = 0;
    const close = () => {
        clearTimeout(timer);
        if (item.classList.contains('is-leaving')) return;
        item.classList.add('is-leaving');
        const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        setTimeout(() => item.remove(), reduced ? 0 : 160);
    };
    const run = () => { since = Date.now(); timer = setTimeout(close, left); };
    const hold = () => {
        if (held++ > 0) return;
        clearTimeout(timer);
        left = Math.max(2000, left - (Date.now() - since));
    };
    const release = () => { if (held > 0 && --held === 0) run(); };

    // A toast that appears under a still pointer doesn't count as pointed at.
    let pointed = false;
    item.addEventListener('pointermove', () => { if (!pointed) { pointed = true; hold(); } });
    item.addEventListener('pointerleave', () => { if (pointed) { pointed = false; release(); } });
    item.addEventListener('focusin', hold);
    item.addEventListener('focusout', release);
    item.querySelector('[data-toast-close]').addEventListener('click', close);

    stack.append(item);
    [...stack.children].slice(0, -MOST).forEach((old) => old.remove());
    run();
}

/** Shows each server notice ([data-toast]) the first time it appears. */
function showNew() {
    document.querySelectorAll('[data-toast][data-toast-id]').forEach((el) => {
        if (seen.has(el.dataset.toastId)) return;
        seen.add(el.dataset.toastId);
        toast(el.dataset.toast, el.dataset.toastTone || 'success');
    });
}

showNew();
new MutationObserver(showNew).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['data-toast-id'] });
