import { onPage } from './page.js';
import './appearance.js';
import './forms.js';
import './shell.js';
import './invitation.js';
import './session.js';
import './back.js';
import './selection.js';
import './uploader.js';
import './image-viewer.js';
import './note-window.js';
import { toast } from './toasts.js';

/**
 * Runs `then` once this tab is seen: now when it is, otherwise when the student turns to it. A tab opened in the
 * background and never looked at loads no editor and no document, and asks the server for nothing more.
 */
function whenSeen(then) {
    if (document.visibilityState === 'visible') {
        then();
        return;
    }
    const seen = () => {
        if (document.visibilityState !== 'visible') return;
        document.removeEventListener('visibilitychange', seen);
        then();
    };
    document.addEventListener('visibilitychange', seen);
}

// The note editor is only loaded on a note's page, once the page is seen; it saves and stops by itself when the
// page is left. One started late fetches the note again first (resources/js/note/editor.js).
onPage(() => {
    const noteEditor = document.querySelector('[data-note-editor]');
    if (!noteEditor) return;
    if (document.visibilityState !== 'visible') noteEditor.dataset.late = '';
    whenSeen(() => {
        if (noteEditor.isConnected) import('./note/editor.js').then(({ mount }) => mount(noteEditor));
    });
});

/**
 * Waits until a Word, PowerPoint or Excel preview is ready: the server makes it the first time and answers 202
 * while it is being made or waits its turn (App\Study\FilePreviews), so it is asked again, only while the tab is
 * seen and the page still there. False when the page was left.
 */
async function prepared(frame) {
    for (;;) {
        await new Promise((resolve) => whenSeen(resolve));
        if (!frame.isConnected) return false;
        let response = null;
        try {
            response = await fetch(frame.dataset.pdfSrc, { method: 'HEAD', credentials: 'same-origin' });
        } catch {}
        if (response?.status !== 202) return frame.isConnected;
        await new Promise((resolve) => setTimeout(resolve, (Number(response.headers.get('Retry-After')) || 2) * 1000));
    }
}

// A PDF (or an office file shown as one), once the tab is seen, asked for once: in the browser's own viewer, or drawn
// by PDF.js where the browser can't show a PDF inside the page (phones and tablets). Says 'pdf-shown' once it is there.
onPage(() => {
    const frames = document.querySelectorAll('iframe[data-pdf-src]');
    if (frames.length === 0) return;
    const inline = navigator.pdfViewerEnabled !== false && !window.matchMedia('(hover: none) and (pointer: coarse)').matches;
    frames.forEach(async (frame) => {
        const shown = frame.hasAttribute('data-pdf-made') ? await prepared(frame) : await new Promise((resolve) => whenSeen(() => resolve(frame.isConnected)));
        if (!shown) return;
        if (!inline) {
            import('./pdf-viewer.js').then(({ drawPdf }) => drawPdf(frame));
            return;
        }
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
