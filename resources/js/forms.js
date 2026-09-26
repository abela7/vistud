/*
| Small form behaviours shared by the M1 screens (DESIGN.md §5):
| - a form marked data-busy-on-submit shows its submit button's loading
|   state and ignores repeated submits;
| - a password field's reveal button toggles visibility;
| - the two-factor challenge swaps between the code and recovery-code fields;
| - a button with data-copy-target copies that element's text (or an
|   input's value) and says so.
*/

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-busy-on-submit')) return;

    if (form.getAttribute('aria-busy') === 'true') {
        event.preventDefault();
        return;
    }
    form.setAttribute('aria-busy', 'true');
    const button = event.submitter ?? form.querySelector('button[type="submit"]');
    button?.setAttribute('aria-busy', 'true');
});

// Returning with the back button restores the page from the cache: clear the busy state.
window.addEventListener('pageshow', () => {
    for (const element of document.querySelectorAll('[data-busy-on-submit][aria-busy], [data-busy-on-submit] [aria-busy]')) {
        element.removeAttribute('aria-busy');
    }
});

document.addEventListener('click', (event) => {
    const toggle = event.target.closest('[data-password-toggle]');
    if (!toggle) return;

    const input = document.getElementById(toggle.getAttribute('aria-controls'));
    if (!input) return;
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    toggle.setAttribute('aria-pressed', String(show));
    const label = show ? toggle.dataset.labelHide : toggle.dataset.labelShow;
    toggle.setAttribute('aria-label', label);
    toggle.setAttribute('title', label);
    toggle.querySelector('[data-icon-show]').hidden = show;
    toggle.querySelector('[data-icon-hide]').hidden = !show;
});

document.addEventListener('click', (event) => {
    const swap = event.target.closest('[data-challenge-swap]');
    if (!swap) return;

    const form = swap.closest('form');
    const showRecovery = form.getAttribute('data-challenge-mode') !== 'recovery';
    form.setAttribute('data-challenge-mode', showRecovery ? 'recovery' : 'code');
    form.querySelector('[data-challenge-panel="code"]').hidden = showRecovery;
    form.querySelector('[data-challenge-panel="recovery"]').hidden = !showRecovery;

    const activeName = showRecovery ? 'recovery_code' : 'code';
    const inactiveName = showRecovery ? 'code' : 'recovery_code';
    const active = form.querySelector(`[name="${activeName}"]`);
    const inactive = form.querySelector(`[name="${inactiveName}"]`);
    inactive.value = '';
    inactive.disabled = true;
    active.disabled = false;
    active.focus();
    swap.textContent = showRecovery ? 'Use an authentication code instead' : 'Use a recovery code instead';
});

document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-copy-target]');
    if (!button) return;

    const source = document.getElementById(button.dataset.copyTarget);
    if (!source) return;
    const text =
        source instanceof HTMLInputElement
            ? source.value
            : [...source.querySelectorAll('li')].map((item) => item.textContent.trim()).join('\n') || source.textContent.trim();
    try {
        await navigator.clipboard.writeText(text);
    } catch {
        // Clipboard unavailable (not a secure context): select the text to copy by hand.
        if (source instanceof HTMLInputElement) source.select();
        return;
    }
    const label = button.querySelector('.btn-idle') ?? button;
    const original = label.textContent;
    label.textContent = button.dataset.copiedLabel ?? 'Copied';
    setTimeout(() => (label.textContent = original), 2000);
});
