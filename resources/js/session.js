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
 * - [data-countdown]: a Pomodoro phase counting down from data-remaining
 *   while data-running is "1". It fills the ring around it (--progress on
 *   [data-ring]), shows in the tab's title, and when it reaches zero fires
 *   "vistud-phase-end" (the page asks the server for the next phase) and,
 *   once across all tabs, a chime and a notification if they are allowed.
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

function countdown(seconds) {
    const s = Math.max(0, Math.ceil(seconds));
    const pad = (n) => String(n).padStart(2, '0');
    return s >= 3600 ? format(s) : `${pad(Math.floor(s / 60))}:${pad(s % 60)}`;
}

const baseTitle = document.title;

function tickCountdowns() {
    let title = null;
    for (const el of document.querySelectorAll('[data-countdown]')) {
        const key = `${el.dataset.remaining}|${el.dataset.running}|${el.dataset.drawn}`;
        let seen = drawn.get(el);
        if (!seen || seen.key !== key) {
            seen = { key, at: performance.now(), ended: false };
            drawn.set(el, seen);
        }
        const running = el.dataset.running === '1';
        const remaining = Number(el.dataset.remaining) - (running ? (performance.now() - seen.at) / 1000 : 0);
        const text = countdown(remaining);
        if (el.textContent !== text) el.textContent = text;
        const total = Number(el.dataset.total) || 1;
        el.closest('[data-ring]')?.style.setProperty('--progress', String(Math.min(1, Math.max(0, 1 - remaining / total))));
        if (running && title === null) title = `${text} ${el.dataset.phaseWords ?? ''} · ${baseTitle}`;
        if (running && remaining <= 0 && !seen.ended) {
            seen.ended = true;
            announce(el.dataset.phaseKey, el.dataset.next);
            // A moment's grace, so the server's clock has passed the end too.
            setTimeout(() => el.dispatchEvent(new CustomEvent('vistud-phase-end')), 1200);
        }
    }
    const next = title ?? baseTitle;
    if (document.title !== next) document.title = next;
}

/** The end of a phase, once across every tab: a chime, and a notification if allowed. */
function announce(key, words) {
    if (!key) return;
    try {
        if (localStorage.getItem('vistud.pomodoro.announced') === key) return;
        localStorage.setItem('vistud.pomodoro.announced', key);
    } catch {
        // Private mode without storage: announce anyway.
    }
    let sound = true;
    try {
        sound = localStorage.getItem('vistud.pomodoro.sound') !== 'false';
    } catch {
        // Keep the default.
    }
    if (sound) chime();
    if ('Notification' in window && Notification.permission === 'granted' && words) {
        try {
            new Notification('ViStud', { body: words, tag: 'vistud-pomodoro' });
        } catch {
            // Some browsers only notify from a service worker; the chime is enough.
        }
    }
}

/** Two soft tones, made in the browser (no sound file). */
function chime() {
    const Context = window.AudioContext || window.webkitAudioContext;
    if (!Context) return;
    try {
        const audio = new Context();
        [[660, 0], [880, 0.35]].forEach(([frequency, start]) => {
            const tone = audio.createOscillator();
            const gain = audio.createGain();
            tone.type = 'sine';
            tone.frequency.value = frequency;
            gain.gain.setValueAtTime(0.0001, audio.currentTime + start);
            gain.gain.exponentialRampToValueAtTime(0.25, audio.currentTime + start + 0.03);
            gain.gain.exponentialRampToValueAtTime(0.0001, audio.currentTime + start + 0.6);
            tone.connect(gain).connect(audio.destination);
            tone.start(audio.currentTime + start);
            tone.stop(audio.currentTime + start + 0.65);
        });
        setTimeout(() => audio.close(), 1500);
    } catch {
        // No sound available.
    }
}

tick();
tickCountdowns();
setInterval(() => {
    tick();
    tickCountdowns();
}, 1000);
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
