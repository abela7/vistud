/*
 * The study clock in the browser (docs/specs/study-memory.md §4). The
 * server keeps the time; this only makes it tick between updates, tells the
 * server the page is in use, and keeps other tabs in step.
 *
 * - [data-clock]: shows data-base seconds, plus the time since it was drawn
 *   while data-running is "1". Counted with this device's clock from the
 *   moment the element was drawn, so a wrong system clock doesn't matter.
 * - [data-session-heartbeat]: while it's on the page (the clock is running),
 *   a "vistud-heartbeat" event every minute the page is in use: visible, and
 *   focused or touched in the last minute. The server pauses a session after
 *   30 minutes without one.
 * - A Livewire "session-changed" event is passed to the other tabs.
 */

const drawn = new WeakMap();

function format(seconds) {
    const s = Math.max(0, Math.floor(seconds));
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const pad = (n) => String(n).padStart(2, '0');
    return `${h}:${pad(m)}:${pad(s % 60)}`;
}

function tick() {
    for (const el of document.querySelectorAll('[data-clock]')) {
        const key = `${el.dataset.base}|${el.dataset.running}|${el.dataset.drawn}`;
        let seen = drawn.get(el);
        if (!seen || seen.key !== key) {
            seen = { key, at: performance.now() };
            drawn.set(el, seen);
        }
        const running = el.dataset.running === '1';
        const seconds = Number(el.dataset.base) + (running ? (performance.now() - seen.at) / 1000 : 0);
        const text = format(seconds);
        if (el.textContent !== text) el.textContent = text;
    }
}

let lastInput = Date.now();
for (const type of ['pointerdown', 'keydown', 'wheel', 'touchstart', 'scroll']) {
    window.addEventListener(type, () => { lastInput = Date.now(); }, { passive: true, capture: true });
}

function inUse() {
    return document.visibilityState === 'visible' && (document.hasFocus() || Date.now() - lastInput < 60_000);
}

function heartbeat() {
    if (!inUse()) return;
    for (const el of document.querySelectorAll('[data-session-heartbeat]')) {
        el.dispatchEvent(new CustomEvent('vistud-heartbeat'));
    }
}

tick();
setInterval(tick, 1000);
setInterval(heartbeat, 60_000);

// Other tabs: a change here refreshes the clock there.
if ('BroadcastChannel' in window) {
    const channel = new BroadcastChannel('vistud-session');
    let relaying = false;
    window.addEventListener('session-changed', () => {
        if (!relaying) channel.postMessage('changed');
    });
    channel.onmessage = () => {
        if (!window.Livewire) return;
        relaying = true;
        try {
            window.Livewire.dispatch('session-changed');
        } finally {
            relaying = false;
        }
    };
}
