/*
| The signed-in frame's behaviour (resources/views/components/layouts/app.blade.php):
| - the slide-in menu is a native modal <dialog>: it traps focus, closes with
|   Esc, and returns focus to the menu button; a tap on the dimmed backdrop
|   also closes it, and it closes itself when the window grows to desktop;
| - the desktop sidebar collapses to icons, remembered in this browser (the
|   inline head script applies it before the first paint);
| - the account menu opens from its button, and closes with Esc, a click
|   outside, or a second click.
*/

import { onPage } from './page.js';
import { clearPlacement, placeMenu } from './floating.js';

const root = document.documentElement;
const SIDEBAR_KEY = 'vistud.sidebar';
const desktop = window.matchMedia('(min-width: 1280px)');

function openerFor(dialog) {
    return document.querySelector(`[data-drawer-open][aria-controls="${dialog.id}"]`);
}

document.addEventListener('click', (event) => {
    const opener = event.target.closest('[data-drawer-open]');
    if (opener) {
        const dialog = document.getElementById(opener.getAttribute('aria-controls'));
        dialog?.showModal();
        opener.setAttribute('aria-expanded', 'true');
        return;
    }

    const closer = event.target.closest('[data-drawer-close]');
    if (closer) {
        closer.closest('dialog')?.close();
        return;
    }

    // A click on the backdrop lands on the dialog itself; the panel fills the rest.
    if (event.target instanceof HTMLDialogElement && event.target.classList.contains('drawer')) {
        event.target.close();
    }
});

document.addEventListener(
    'close',
    (event) => {
        if (event.target instanceof HTMLDialogElement && event.target.classList.contains('drawer')) {
            openerFor(event.target)?.setAttribute('aria-expanded', 'false');
        }
    },
    true,
);

desktop.addEventListener('change', () => {
    if (desktop.matches) {
        document.querySelectorAll('dialog.drawer[open]').forEach((dialog) => dialog.close());
    }
});

// ---------- Sidebar collapse ----------

function syncSidebarToggle() {
    const collapsed = root.dataset.sidebar === 'collapsed';
    for (const toggle of document.querySelectorAll('[data-sidebar-toggle]')) {
        const label = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
        toggle.setAttribute('title', label);
        const text = toggle.querySelector('.nav-label');
        if (text) text.textContent = label;
    }
}

document.addEventListener('click', (event) => {
    if (!event.target.closest('[data-sidebar-toggle]')) return;
    const collapsed = root.dataset.sidebar !== 'collapsed';
    root.dataset.sidebar = collapsed ? 'collapsed' : 'expanded';
    try {
        localStorage.setItem(SIDEBAR_KEY, collapsed ? 'collapsed' : 'expanded');
    } catch {
        // Storage unavailable: the choice lasts for this page.
    }
    syncSidebarToggle();
});

onPage(syncSidebarToggle);

// A page swapped in without reloading (resources/js/page.js) brings the server's <html> attributes, which
// don't know the sidebar is collapsed: it stays as it was.
document.addEventListener('livewire:navigating', (event) => {
    const sidebar = root.dataset.sidebar;
    event.detail?.onSwap?.(() => {
        if (sidebar) root.dataset.sidebar = sidebar;
    });
});

// ---------- Menus ----------
// A button with data-menu-button opens the panel its aria-controls names (data-menu-panel). A panel that is a
// popover is shown in the browser's top layer and placed beside its button (resources/js/floating.js), so
// nothing clips it and it is always inside the window; the account menu is its own fixed panel.

let placed = null;
let watching = null;

function place() {
    if (!placed) return;
    const { button, panel } = placed;
    const box = button.getBoundingClientRect();
    // Its button scrolled out of the window: the menu goes with it.
    if (box.bottom < 0 || box.top > window.innerHeight || box.right < 0 || box.left > window.innerWidth) {
        setMenu(button, false);
        return;
    }
    // Measuring lets it grow to its full height for a moment: where the list was scrolled to stays.
    const scrolled = panel.scrollTop;
    placeMenu(button, panel, panel.dataset.menuAlign === 'start' ? 'start' : 'end');
    panel.scrollTop = scrolled;
}

let scheduled = false;
function placeSoon() {
    if (scheduled || !placed) return;
    scheduled = true;
    requestAnimationFrame(() => {
        scheduled = false;
        place();
    });
}

window.addEventListener('resize', placeSoon);
// The page scrolling moves the button; the menu's own list scrolling doesn't.
document.addEventListener('scroll', (event) => {
    if (placed && event.target instanceof Node && placed.panel.contains(event.target)) return;
    placeSoon();
}, true);

function setMenu(button, open) {
    const panel = document.getElementById(button.getAttribute('aria-controls'));
    if (!panel) return;
    const floating = panel.hasAttribute('popover') && typeof panel.showPopover === 'function';
    if (open) {
        panel.hidden = false;
        if (floating) {
            if (!panel.matches(':popover-open')) panel.showPopover();
            placed = { button, panel };
            place();
            // A list that grows or shrinks while it is open (a pin taken away) keeps its place.
            watching?.disconnect();
            watching = 'ResizeObserver' in window ? new ResizeObserver(placeSoon) : null;
            watching?.observe(panel);
        }
    } else {
        if (floating) {
            if (panel.matches(':popover-open')) panel.hidePopover();
            clearPlacement(panel);
            if (placed?.panel === panel) {
                placed = null;
                watching?.disconnect();
                watching = null;
            }
        }
        panel.hidden = true;
    }
    button.setAttribute('aria-expanded', String(open));
}

function openMenuButton() {
    return document.querySelector('[data-menu-button][aria-expanded="true"]');
}

document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-menu-button]');
    const open = openMenuButton();
    if (button) {
        // One menu at a time.
        if (open && open !== button) setMenu(open, false);
        setMenu(button, button.getAttribute('aria-expanded') !== 'true');
        return;
    }
    // A click outside the open menu, or on one of its items, closes it.
    if (open && (!event.target.closest('[data-menu-panel]') || event.target.closest('.menu-item'))) {
        setMenu(open, false);
    }
});

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    const open = openMenuButton();
    if (open) {
        setMenu(open, false);
        open.focus();
    }
});

// A top bar that very large text has made tall stops sticking, so it never covers most of the page.
let watchedTopbar = null;
const fitTopbar = () => watchedTopbar?.classList.toggle('is-tall', watchedTopbar.offsetHeight > window.innerHeight * 0.25);
const topbarSize = 'ResizeObserver' in window ? new ResizeObserver(fitTopbar) : null;
window.addEventListener('resize', fitTopbar);
onPage(() => {
    if (watchedTopbar) topbarSize?.unobserve(watchedTopbar);
    watchedTopbar = document.querySelector('.app-topbar');
    if (watchedTopbar) topbarSize?.observe(watchedTopbar);
});

// The phone tab bar's height, for what floats above it (the pinned notes' button, resources/css/shell.css): it is
// taller where its labels wrap, or when the text is large.
let watchedTabbar = null;
const fitTabbar = () => {
    if (watchedTabbar) root.style.setProperty('--tabbar-height', `${watchedTabbar.offsetHeight}px`);
    else root.style.removeProperty('--tabbar-height');
};
const tabbarSize = 'ResizeObserver' in window ? new ResizeObserver(fitTabbar) : null;
onPage(() => {
    if (watchedTabbar) tabbarSize?.unobserve(watchedTabbar);
    watchedTabbar = document.querySelector('.app-tabbar');
    if (watchedTabbar) tabbarSize?.observe(watchedTabbar);
    fitTabbar();
});
