/*
| Appearance: system, light or dark, and which theme light mode uses (ADR 0003 §6.4, DESIGN.md §3.6).
| The inline script in <head> (resources/views/partials/theme-script.blade.php)
| applies the saved choice before the first paint; this module handles
| changes. Switching only changes <html data-theme>: every colour and
| gradient follows through the CSS variables, with no reload and no
| component code involved.
|
| Until accounts store the preference (M2), it is kept in this browser.
*/

const STORAGE_KEY = 'vistud.appearance';
const LIGHT_THEME_KEY = 'vistud.theme-light';
const MODES = ['system', 'light', 'dark'];
const prefersDark = window.matchMedia('(prefers-color-scheme: dark)');
const root = document.documentElement;

function themeFor(mode) {
    const dark = mode === 'dark' || (mode === 'system' && prefersDark.matches);

    return dark ? root.dataset.themeDark : root.dataset.themeLight;
}

function isLightTheme(id) {
    return (window.vistudLightThemes ?? []).includes(id);
}

/** The light theme saved in this browser, put back on <html> (a page swapped in brings the default). */
function restoreLightTheme() {
    try {
        const id = localStorage.getItem(LIGHT_THEME_KEY);
        if (isLightTheme(id)) root.dataset.themeLight = id;
    } catch {
        // Storage unavailable: the page's default light theme stays.
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

export function setLightTheme(id) {
    if (!isLightTheme(id)) return;
    try {
        localStorage.setItem(LIGHT_THEME_KEY, id);
    } catch {
        // Storage can be unavailable (private windows); the choice still applies to this page.
    }
    root.dataset.themeLight = id;
    applyAppearance(MODES.includes(root.dataset.appearance) ? root.dataset.appearance : 'system');
}

function syncControls() {
    for (const input of document.querySelectorAll('input[data-appearance-option]')) {
        input.checked = input.value === root.dataset.appearance;
    }
    for (const input of document.querySelectorAll('input[data-light-theme-option]')) {
        input.checked = input.value === root.dataset.themeLight;
    }
}

prefersDark.addEventListener('change', () => {
    if (root.dataset.appearance === 'system') applyAppearance('system');
});

document.addEventListener('change', (event) => {
    const mode = event.target.closest('input[data-appearance-option]');
    if (mode) setAppearance(mode.value);
    const theme = event.target.closest('input[data-light-theme-option]');
    if (theme) setLightTheme(theme.value);
});

// Another tab changed the appearance or the light theme.
window.addEventListener('storage', (event) => {
    if (event.key === STORAGE_KEY && MODES.includes(event.newValue)) applyAppearance(event.newValue);
    if (event.key === LIGHT_THEME_KEY && isLightTheme(event.newValue)) {
        root.dataset.themeLight = event.newValue;
        applyAppearance(root.dataset.appearance);
    }
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
        restoreLightTheme();
        applyAppearance(MODES.includes(mode) ? mode : 'system');
    });
});

syncControls();
