/*
| A note in a window of its own, beside the study material (the owner's
| review, 2026-09-29). On a computer it opens as a window on the right half
| of the screen, and a second press brings the same window forward; on a
| phone or a tablet it is a new tab. Links marked data-note-window open this
| way; with Ctrl, Shift or the middle button they open as the browser would.
*/

const WIDE = '(pointer: fine) and (min-width: 1024px)';

/** The features for a window on the right half of the screen, or '' where windows are tabs. */
function halfScreen() {
    if (!window.matchMedia(WIDE).matches) return '';
    const { availWidth, availHeight } = window.screen;
    const left = window.screen.availLeft ?? 0;
    const top = window.screen.availTop ?? 0;
    const width = Math.max(480, Math.round(availWidth / 2));
    return `popup,width=${width},height=${availHeight},left=${left + availWidth - width},top=${top}`;
}

/**
 * Opens `url` in the window called `name` ('_blank' for a new one each time) and returns it, or null when
 * the browser blocked it. A window already showing `url` is only brought forward, never reloaded. With no
 * url the window opens empty, to be given its address later with show() (after a save the note is waiting
 * on): browsers allow a new window only straight after a press.
 */
export function openNoteWindow(url, name = '_blank') {
    const opened = window.open('', name, halfScreen());
    if (!opened) return null;
    // A new window, until its address is loaded: the page's background, not a white flash.
    try {
        if (opened.location.href === 'about:blank') {
            const bodyBg = getComputedStyle(document.body).backgroundColor;
            const docStyle = getComputedStyle(document.documentElement);
            const bg = bodyBg || docStyle.backgroundColor;
            opened.document.documentElement.style.backgroundColor = bg;
            opened.document.documentElement.style.colorScheme = docStyle.colorScheme;
            if (opened.document.body) opened.document.body.style.backgroundColor = bg;
        }
    } catch {}
    if (url) show(opened, url);
    opened.focus();
    return opened;
}

/** Gives a window opened empty its address, unless it shows that page already. */
export function show(opened, url) {
    let current = null;
    try {
        current = opened.location.href;
    } catch {}
    if (current !== new URL(url, window.location.href).href) opened.location.replace(url);
}

document.addEventListener('click', (event) => {
    const link = event.target instanceof Element ? event.target.closest('a[data-note-window]') : null;
    if (!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    // Where windows are tabs, the link's own target opens one.
    if (!halfScreen()) return;
    if (openNoteWindow(link.href, link.target || '_blank')) event.preventDefault();
});
