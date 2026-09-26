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

syncSidebarToggle();

// ---------- Account menu ----------

function setMenu(button, open) {
    const panel = document.getElementById(button.getAttribute('aria-controls'));
    if (!panel) return;
    panel.hidden = !open;
    button.setAttribute('aria-expanded', String(open));
}

function openMenuButton() {
    return document.querySelector('[data-menu-button][aria-expanded="true"]');
}

document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-menu-button]');
    const open = openMenuButton();
    if (button) {
        setMenu(button, button.getAttribute('aria-expanded') !== 'true');
        return;
    }
    if (open && !event.target.closest('[data-menu-panel]')) {
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
