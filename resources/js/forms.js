/*
| Small form behaviours shared by the M1 screens (DESIGN.md §5):
| - a form marked data-busy-on-submit shows its submit button's loading
|   state and ignores repeated submits;
| - a password field's reveal button toggles visibility.
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
