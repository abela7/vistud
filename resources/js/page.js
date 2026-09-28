/*
| Moving between pages without reloading (the owner's review, 2026-09-28).
| A link inside ViStud fetches the next page and swaps it in (Livewire's
| wire:navigate): no white flash, and the page is fetched as soon as the
| pointer rests on the link. Livewire replaces the <body>, so a script that
| sets up the page registers with onPage() and runs again on each new one.
|
| A link is left to the browser when it leaves ViStud, opens elsewhere
| (target, download), goes to a file's bytes, or says data-no-navigate.
*/

const setups = [];
let current = document.body;

/** Runs `setup` for this page now, and again for every page navigated to. */
export function onPage(setup) {
    setups.push(setup);
    setup();
}

document.addEventListener('livewire:navigated', () => {
    // Livewire also says so for the page it started on: that one is set up already.
    if (document.body === current) return;
    current = document.body;
    setups.forEach((setup) => setup());
});

// ---------- Which links navigate in place ----------

const NATIVE = /\/content$|\/notes\/images\/|\/logout$|\/export$/;

function navigable(link) {
    if (link.hasAttribute('wire:navigate') || link.hasAttribute('wire:navigate.hover')) return false;
    // Back links go back through the history when they can (resources/js/back.js).
    if (link.hasAttribute('data-back') || link.hasAttribute('target') || link.hasAttribute('download') || link.closest('[data-no-navigate]')) return false;
    const raw = link.getAttribute('href');
    if (!raw || raw.startsWith('#') || raw.startsWith('javascript:')) return false;
    let url;
    try {
        url = new URL(link.href, window.location.href);
    } catch {
        return false;
    }
    if (url.origin !== window.location.origin || url.searchParams.has('download')) return false;
    // The same page with another #fragment is a jump, not a new page.
    if (url.pathname === window.location.pathname && url.search === window.location.search && url.hash) return false;
    return !NATIVE.test(url.pathname);
}

function markLinks(root) {
    if (!window.Livewire || !(root instanceof Element || root instanceof Document)) return;
    const links = root instanceof HTMLAnchorElement ? [root] : root.querySelectorAll('a[href]');
    for (const link of links) {
        if (navigable(link)) link.setAttribute('wire:navigate.hover', '');
    }
}

// Links in the page as it arrives, before Alpine starts on it…
document.addEventListener('livewire:navigating', (event) => event.detail?.onSwap?.(() => markLinks(document)));
document.addEventListener('livewire:init', () => markLinks(document));
markLinks(document);
// …and links that Livewire draws later (a list that filters, a panel that opens).
new MutationObserver((changes) => {
    for (const change of changes) change.addedNodes.forEach((node) => markLinks(node));
}).observe(document.documentElement, { childList: true, subtree: true });

// ---------- While the next page loads ----------
// A thin line at the top in the theme's accent, only once a page takes more than a moment. (Livewire's own
// bar is off in config/livewire.php: its markup gives screen readers a role that doesn't exist.) Outside
// the <body>, so a page swapped in doesn't take it away.
const loading = document.createElement('div');
loading.className = 'page-loading';
loading.setAttribute('aria-hidden', 'true');
document.documentElement.append(loading);
let loadingTimer = null;
document.addEventListener('livewire:navigate', (event) => {
    if (event.defaultPrevented || event.detail?.cached) return;
    clearTimeout(loadingTimer);
    loadingTimer = setTimeout(() => loading.classList.add('is-loading'), 120);
});
document.addEventListener('livewire:navigated', () => {
    clearTimeout(loadingTimer);
    if (!loading.classList.contains('is-loading')) return;
    loading.classList.replace('is-loading', 'is-done');
    setTimeout(() => loading.classList.remove('is-done'), 300);
});
