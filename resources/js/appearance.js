/*
| Appearance: the theme (a light and a dark theme together) and system, light or dark, which picks
| between the two (ADR 0003 §6.4, DESIGN.md §3.6).
| The inline script in <head> (resources/views/partials/theme-script.blade.php)
| applies the saved choice before the first paint; this module handles
| changes. Switching only changes <html data-theme>: every colour and
| gradient follows through the CSS variables, with no reload and no
| component code involved.
|
| Until accounts store the preference (M2), it is kept in this browser.
*/

const STORAGE_KEY = 'vistud.appearance';
const THEME_KEY = 'vistud.theme';
const MODES = ['system', 'light', 'dark'];
const prefersDark = window.matchMedia('(prefers-color-scheme: dark)');
const root = document.documentElement;

function themeFor(mode) {
    const dark = mode === 'dark' || (mode === 'system' && prefersDark.matches);

    return dark ? root.dataset.themeDark : root.dataset.themeLight;
}

/** @returns {[string, string]|null} the light and dark theme IDs of a theme a student may choose */
function pairOf(id) {
    const themes = window.vistudThemes ?? {};

    return Object.hasOwn(themes, id) ? themes[id] : null;
}

function usePair(id) {
    const pair = pairOf(id);
    if (!pair) return false;
    [root.dataset.themeLight, root.dataset.themeDark] = pair;
    root.dataset.themeChoice = id;

    return true;
}

/** The theme saved in this browser, put back on <html> (a page swapped in brings the default). */
function restoreTheme() {
    try {
        usePair(localStorage.getItem(THEME_KEY));
    } catch {
        // Storage unavailable: the page's default themes stay.
    }
}

export function applyAppearance(mode) {
    const theme = themeFor(mode);
    const changed = root.dataset.theme !== theme;
    root.dataset.appearance = mode;
    root.dataset.theme = theme;
    const scheme = theme === root.dataset.themeDark ? 'dark' : 'light';
    root.style.colorScheme = scheme;
    const meta = document.querySelector('meta[name="color-scheme"]');
    if (meta) meta.content = scheme;
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

export function setTheme(id) {
    if (!usePair(id)) return;
    try {
        localStorage.setItem(THEME_KEY, id);
    } catch {
        // Storage can be unavailable (private windows); the choice still applies to this page.
    }
    applyAppearance(MODES.includes(root.dataset.appearance) ? root.dataset.appearance : 'system');
}

function syncControls() {
    for (const input of document.querySelectorAll('input[data-appearance-option]')) {
        input.checked = input.value === root.dataset.appearance;
    }
    const chosen = root.dataset.themeChoice;
    for (const input of document.querySelectorAll('input[data-theme-option]')) {
        if (chosen) input.checked = input.value === chosen;
    }
}

prefersDark.addEventListener('change', () => {
    if (root.dataset.appearance === 'system') applyAppearance('system');
});

document.addEventListener('change', (event) => {
    const mode = event.target.closest('input[data-appearance-option]');
    if (mode) setAppearance(mode.value);
    const theme = event.target.closest('input[data-theme-option]');
    if (theme) setTheme(theme.value);
});

// Another tab changed the appearance or the theme.
window.addEventListener('storage', (event) => {
    if (event.key === STORAGE_KEY && MODES.includes(event.newValue)) applyAppearance(event.newValue);
    if (event.key === THEME_KEY && usePair(event.newValue)) applyAppearance(root.dataset.appearance);
});

// A page swapped in without reloading (resources/js/page.js) brings its own <html> attributes, the
// default theme among them: the saved choice goes back on before the new page is painted.
document.addEventListener('livewire:navigating', (event) => {
    event.detail?.onSwap?.(() => {
        let mode = 'system';
        try {
            mode = localStorage.getItem(STORAGE_KEY) ?? 'system';
        } catch {
            // Storage unavailable: the page's own choice stays.
            mode = root.dataset.appearance;
        }
        restoreTheme();
        applyAppearance(MODES.includes(mode) ? mode : 'system');
    });
});

restoreTheme();
syncControls();
