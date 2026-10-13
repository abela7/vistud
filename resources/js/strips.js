/*
| Rows that slide sideways: a page's tabs, the calendar's filters, the study session's pane switcher and the guide's
| steps. On a phone they scroll under a finger, but a row that overflows hides its scrollbar, so on its own it gives no
| sign that there is more, may start with the current tab out of sight, and cannot be moved with a mouse. This makes
| every one of them usable the same way:
|
|  - `data-more` says which edge has more ("start", "end" or "both"), and the CSS fades that edge;
|  - the current item is scrolled into view when the row first appears;
|  - a mouse can drag the row, and the wheel moves it while the pointer is over it and it has room to move.
|
| Keyboard users need nothing added: every item in a row is a link or a button, and focusing one scrolls it into view.
| Rows are found as they appear (Livewire draws and replaces them), so no page has to register its own.
*/

const STRIPS = '.page-tabs, .cal-filters, .session-pane-switcher, .guide-steps-list';
const CURRENT = '[aria-current]:not([aria-current="false"]), [aria-selected="true"], [aria-pressed="true"], .is-active, .is-current';

/** Which edges of the row have more beyond them. */
function mark(strip) {
    const room = strip.scrollWidth - strip.clientWidth;
    // Half a pixel of slack: a zoomed page reports fractions.
    const start = room > 1 && strip.scrollLeft > 1;
    const end = room > 1 && strip.scrollLeft < room - 1;
    const more = start && end ? 'both' : start ? 'start' : end ? 'end' : '';
    if (more) strip.dataset.more = more;
    else delete strip.dataset.more;
}

/** Scrolls the row, and only the row, so the current item sits in the middle of it. */
function showCurrent(strip) {
    const item = strip.querySelector(CURRENT);
    if (!item || strip.scrollWidth <= strip.clientWidth) return;
    const row = strip.getBoundingClientRect();
    const box = item.getBoundingClientRect();
    strip.scrollLeft += box.left - row.left - (row.width - box.width) / 2;
}

const watching = new WeakSet();
const resized = 'ResizeObserver' in window ? new ResizeObserver((entries) => entries.forEach((entry) => mark(entry.target.closest(STRIPS) ?? entry.target))) : null;

function start(strip) {
    if (watching.has(strip)) return;
    watching.add(strip);
    // The row's own width changes with the window; its items' widths change with a count or a label.
    resized?.observe(strip);
    [...strip.children].forEach((child) => resized?.observe(child));
    showCurrent(strip);
    mark(strip);
}

function find(node) {
    if (!(node instanceof Element)) return;
    if (node.matches(STRIPS)) start(node);
    node.querySelectorAll(STRIPS).forEach(start);
}

find(document.documentElement);
new MutationObserver((changes) => {
    for (const change of changes) change.addedNodes.forEach(find);
}).observe(document.documentElement, { childList: true, subtree: true });

// A row's scrolling doesn't bubble, so it is heard on the way down.
document.addEventListener('scroll', (event) => {
    if (event.target instanceof Element && event.target.matches(STRIPS)) mark(event.target);
}, true);

// ---------- A mouse ----------

/** The wheel, which only moves things up and down, moves the row sideways while it can. */
document.addEventListener('wheel', (event) => {
    const strip = event.target instanceof Element ? event.target.closest(STRIPS) : null;
    if (!strip || event.ctrlKey || Math.abs(event.deltaX) >= Math.abs(event.deltaY)) return;
    const room = strip.scrollWidth - strip.clientWidth;
    const goingBack = event.deltaY < 0;
    if (room <= 1 || (goingBack ? strip.scrollLeft <= 0 : strip.scrollLeft >= room - 1)) return;
    event.preventDefault();
    // Some browsers (Firefox) count the wheel in lines, not pixels.
    strip.scrollLeft += event.deltaMode === 1 ? event.deltaY * 16 : event.deltaY;
}, { passive: false });

/** Dragging a row with the mouse (a finger already does it): a drag moves the row and is never taken for a click. */
let drag = null;
document.addEventListener('pointerdown', (event) => {
    if (event.pointerType !== 'mouse' || event.button !== 0 || !(event.target instanceof Element)) return;
    const strip = event.target.closest(STRIPS);
    if (!strip || strip.scrollWidth <= strip.clientWidth || event.target.closest('input, textarea, select, [contenteditable]')) return;
    drag = { strip, x: event.clientX, from: strip.scrollLeft, moved: false };
});

// A link or an image in a row would start the browser's own drag-and-drop, which ends the drag before it begins.
document.addEventListener('dragstart', (event) => {
    if (event.target instanceof Element && event.target.closest(STRIPS)) event.preventDefault();
});

document.addEventListener('pointermove', (event) => {
    if (!drag) return;
    const by = event.clientX - drag.x;
    if (!drag.moved && Math.abs(by) < 6) return;
    drag.moved = true;
    drag.strip.scrollLeft = drag.from - by;
    drag.strip.classList.add('is-dragging');
});

function release() {
    if (!drag) return;
    const { strip, moved } = drag;
    drag = null;
    strip.classList.remove('is-dragging');
    if (!moved) return;
    // The click that ends a drag lands on whatever is under the mouse: it is not meant for it.
    const swallow = (event) => event.stopPropagation() || event.preventDefault();
    document.addEventListener('click', swallow, { capture: true, once: true });
    setTimeout(() => document.removeEventListener('click', swallow, true), 0);
}
document.addEventListener('pointerup', release);
document.addEventListener('pointercancel', release);
