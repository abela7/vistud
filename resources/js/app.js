import { onPage } from './page.js';
import './appearance.js';
import './forms.js';
import './shell.js';
import './invitation.js';
import './session.js';
import './back.js';
import './selection.js';
import './uploader.js';
import './note-window.js';
import { toast } from './toasts.js';

// The note editor is only loaded on a note's page; it saves and stops by itself when the page is left.
onPage(() => {
    const noteEditor = document.querySelector('[data-note-editor]');
    if (noteEditor) import('./note/editor.js').then(({ mount }) => mount(noteEditor));
});

// A PDF (or an office file shown as one), asked for once: in the browser's own viewer, or drawn by PDF.js where the
// browser can't show a PDF inside the page (phones and tablets). Either says 'pdf-shown' once it is there.
onPage(() => {
    const frames = document.querySelectorAll('iframe[data-pdf-src]');
    if (frames.length === 0) return;
    const inline = navigator.pdfViewerEnabled !== false && !window.matchMedia('(hover: none) and (pointer: coarse)').matches;
    if (!inline) {
        import('./pdf-viewer.js').then(({ drawPdf }) => frames.forEach(drawPdf));
        return;
    }
    frames.forEach((frame) => {
        frame.addEventListener('load', () => {
            frame.dataset.shown = '';
            frame.dispatchEvent(new CustomEvent('pdf-shown', { bubbles: true }));
        }, { once: true });
        frame.src = frame.dataset.pdfSrc;
    });
});

// A Markdown file shown on its page: its formulas are drawn only when it has any.
onPage(() => {
    const preview = document.querySelector('[data-formulas]');
    if (preview && preview.textContent.includes('$')) import('./formulas.js').then(({ drawFormulas }) => drawFormulas(preview));
});

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
        if (purged > 0) toast(`Unsaved changes to ${purged === 1 ? 'a note' : `${purged} notes`} deleted on another device were removed from this one.`, 'info');
    }).catch(() => {});
}
