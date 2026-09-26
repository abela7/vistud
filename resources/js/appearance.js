/*
| Appearance: system, light or dark (ADR 0003 §6.4, DESIGN.md §3.6).
| The inline script in <head> (resources/views/partials/theme-script.blade.php)
| applies the saved choice before the first paint; this module handles
| changes. Switching only changes <html data-theme>: every colour and
| gradient follows through the CSS variables, with no reload and no
| component code involved.
|
| Until accounts store the preference (M2), it is kept in this browser.
*/

const STORAGE_KEY = 'vistud.appearance';
const MODES = ['system', 'light', 'dark'];
const prefersDark = window.matchMedia('(prefers-color-scheme: dark)');
const root = document.documentElement;

function themeFor(mode) {
    const dark = mode === 'dark' || (mode === 'system' && prefersDark.matches);

    return dark ? root.dataset.themeDark : root.dataset.themeLight;
}

export function applyAppearance(mode) {
    const theme = themeFor(mode);
    const changed = root.dataset.theme !== theme;
    root.dataset.appearance = mode;
    root.dataset.theme = theme;
    syncControls();
    if (changed) {
        window.dispatchEvent(new CustomEvent('vistud:theme-changed', { detail: { mode, theme } }));
    }
}

export function setAppearance(mode) {
    if (!MODES.includes(mode)) return;
    try {
        localStorage.setItem(STORAGE_KEY, mode);
    } catch {
        // Storage can be unavailable (private windows); the choice still applies to this page.
    }
    applyAppearance(mode);
}

function syncControls() {
    for (const input of document.querySelectorAll('input[data-appearance-option]')) {
        input.checked = input.value === root.dataset.appearance;
    }
}

prefersDark.addEventListener('change', () => {
    if (root.dataset.appearance === 'system') applyAppearance('system');
});

document.addEventListener('change', (event) => {
    const input = event.target.closest('input[data-appearance-option]');
    if (input) setAppearance(input.value);
});

// Another tab changed the appearance.
window.addEventListener('storage', (event) => {
    if (event.key === STORAGE_KEY && MODES.includes(event.newValue)) applyAppearance(event.newValue);
});

syncControls();
