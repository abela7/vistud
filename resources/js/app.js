import './appearance.js';
import './forms.js';
import './shell.js';
import './invitation.js';

// The note editor is only loaded on a note's page.
const noteEditor = document.querySelector('[data-note-editor]');
if (noteEditor) {
    import('./note/editor.js').then(({ mount }) => mount(noteEditor));
}

// A student's note drafts on this device (resources/js/note/).
const account = document.querySelector('meta[name="vistud-account"]')?.content;
if (account) {
    // Logging out with unsaved drafts asks first. Before forms.js marks the form busy.
    window.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-logout-form')) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        import('./note/logout.js').then(({ beforeLogout }) => beforeLogout(form, account)).catch(() => form.submit());
    }, true);

    // Drafts of notes deleted elsewhere go before anything could send them.
    import('./note/drafts.js').then(async ({ hasDrafts, openDrafts }) => {
        if (!(await hasDrafts(account))) return;
        const drafts = await openDrafts(account);
        const { purgeGone } = await import('./note/sync.js');
        const purged = await purgeGone(account, drafts);
        drafts.close();
        if (purged > 0) notice(`Unsaved changes to ${purged === 1 ? 'a note' : `${purged} notes`} deleted on another device were removed from this one.`);
    }).catch(() => {});
}

/** A short message in the corner, read out by screen readers. */
function notice(text) {
    const box = document.querySelector('[data-app-notice]');
    if (!box) return;
    box.querySelector('[data-app-notice-text]').textContent = text;
    document.querySelector('[data-app-notice-live]').textContent = text;
    box.hidden = false;
    box.querySelector('[data-app-notice-close]').onclick = () => { box.hidden = true; };
}
