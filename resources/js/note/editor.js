/*
| The note editor (ADR 0003 §5, docs/specs/workspaces.md step 3). Tiptap, on
| a page element that no Livewire component renders. It restores a draft left
| in this browser, autosaves through PUT /api/v1/notes/{id}, and says honestly
| where the text is: on the server, only on this device, or at risk.
*/

import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import { TaskItem, TaskList } from '@tiptap/extension-list';
import { Placeholder } from '@tiptap/extensions';
import { UniqueID } from '@tiptap/extension-unique-id';
import { deleteDrafts, openDrafts } from './drafts.js';
import { createAutosave, randomId } from './autosave.js';

const BLOCKS = ['paragraph', 'heading', 'blockquote', 'bulletList', 'orderedList', 'taskList', 'codeBlock', 'horizontalRule', 'listItem', 'taskItem'];

const LABELS = {
    saved: 'Saved',
    local: 'Saved on this device',
    offline: 'Saved on this device',
    saving: 'Saving…',
    retrying: 'Not saved, retrying in {s} s',
    nostorage: 'Not stored on this device, keep this tab open',
    conflict: 'Conflict, needs your choice',
    gone: 'Not saved',
    session: 'Not saved',
    blocked: 'Can\'t be saved: access removed',
    deleted: 'Not saved',
    rejected: 'Not saved',
    account: 'Not saved',
};

/** Toolbar buttons: what they do, and when they show as pressed. */
const COMMANDS = {
    bold: [(c) => c.toggleBold(), (e) => e.isActive('bold')],
    italic: [(c) => c.toggleItalic(), (e) => e.isActive('italic')],
    heading2: [(c) => c.toggleHeading({ level: 2 }), (e) => e.isActive('heading', { level: 2 })],
    heading3: [(c) => c.toggleHeading({ level: 3 }), (e) => e.isActive('heading', { level: 3 })],
    bulletList: [(c) => c.toggleBulletList(), (e) => e.isActive('bulletList')],
    orderedList: [(c) => c.toggleOrderedList(), (e) => e.isActive('orderedList')],
    taskList: [(c) => c.toggleTaskList(), (e) => e.isActive('taskList')],
    blockquote: [(c) => c.toggleBlockquote(), (e) => e.isActive('blockquote')],
    codeBlock: [(c) => c.toggleCodeBlock(), (e) => e.isActive('codeBlock')],
    divider: [(c) => c.setHorizontalRule(), null],
    undo: [(c) => c.undo(), null],
    redo: [(c) => c.redo(), null],
};

/** This tab's ID: kept across reloads of the tab, new in every other tab. */
function tabId() {
    try {
        let id = sessionStorage.getItem('vistud.tab');
        if (!id) sessionStorage.setItem('vistud.tab', (id = randomId(12)));
        return id;
    } catch {
        return randomId(12);
    }
}

export async function mount(host) {
    const note = JSON.parse(host.querySelector('[data-note-doc]').textContent);
    const accountId = host.dataset.account;
    const titleField = host.querySelector('[data-note-title]');
    const status = document.querySelector('[data-save-status]');
    const alerts = document.querySelector('[data-note-alerts]');
    const heading = document.querySelector('[data-note-heading]');
    const toolbar = host.querySelector('[data-note-toolbar]');
    const buttons = [...toolbar.querySelectorAll('button[data-command]')];
    const clientId = tabId();
    let loading = true;
    let editor = null;
    let autosave = null;

    const show = (name) => alerts.querySelectorAll('[data-alert]').forEach((el) => { el.hidden = el.dataset.alert !== name; });
    const setTitle = () => {
        const title = titleField.value.trim() || 'Untitled note';
        heading.textContent = title;
        document.title = document.title.replace(/^[^·]*·/, `${title} ·`);
    };
    const growTitle = () => {
        titleField.style.height = 'auto';
        titleField.style.height = `${titleField.scrollHeight}px`;
    };

    let drafts = null;
    try {
        drafts = await openDrafts(accountId);
        await drafts.expire();
    } catch {
        drafts = null;
    }

    editor = new Editor({
        element: host.querySelector('[data-note-body]'),
        // Its default styles hardcode colours; resources/css/editor.css has themed ones.
        injectCSS: false,
        content: note.doc,
        extensions: [
            StarterKit.configure({
                heading: { levels: [1, 2, 3] },
                dropcursor: { color: 'var(--drop-indicator)', width: 2 },
                link: { openOnClick: false, autolink: true, protocols: [], defaultProtocol: 'https', isAllowedUri: (url) => /^(https?:\/\/|mailto:)/i.test(url) },
            }),
            TaskList,
            TaskItem.configure({ nested: true }),
            Placeholder.configure({ placeholder: 'Start writing…' }),
            UniqueID.configure({ types: BLOCKS, generateID: () => randomId(8) }),
        ],
        editorProps: {
            attributes: { class: 'note-prose', 'aria-label': 'Note', 'aria-multiline': 'true', role: 'textbox' },
        },
        onUpdate: () => { if (!loading) autosave?.changed(); },
        onTransaction: () => updateToolbar(),
    });

    // Tabs of this account tell each other what they saved (ADR 0003 §5.3). Other accounts' tabs never hear it.
    const channel = 'BroadcastChannel' in window ? new BroadcastChannel(`vistud-${accountId}`) : null;

    autosave = createAutosave({
        url: host.dataset.saveUrl,
        noteId: note.id,
        clientId,
        accountId,
        version: note.version,
        drafts,
        snapshot: () => ({ title: titleField.value, doc: editor.getJSON() }),
        onState: (state) => {
            status.dataset.state = state.status;
            status.querySelector('[data-save-label]').textContent = LABELS[state.status].replace('{s}', state.retryIn);
            if (['conflict', 'gone', 'session', 'blocked', 'deleted', 'rejected', 'offline', 'nostorage'].includes(state.status)) {
                show(state.status);
                if (state.status === 'rejected') alerts.querySelector('[data-rejected-message]').textContent = state.message;
            } else if (alerts.querySelector('[data-alert]:not([hidden])')?.dataset.alert !== 'restored') {
                show(null);
            }
            // A choice waits until no older save is still on its way.
            alerts.querySelectorAll('[data-action="keep-mine"], [data-action="keep-theirs"]').forEach((b) => { b.disabled = state.busy; });
            if (state.status === 'account') window.location.assign('/login');
        },
        onSaved: (saved) => channel?.postMessage({ type: 'note-saved', note: note.id, version: saved.version, client: clientId }),
        onAccountDeleted: () => deleteDrafts(accountId).catch(() => {}),
    });

    // ---------- A draft left in this browser ----------
    const [draft] = drafts ? await drafts.forNote(note.id).catch(() => []) : [];
    if (draft) {
        editor.commands.setContent(draft.doc, { emitUpdate: false });
        titleField.value = draft.title ?? '';
        // Stored as this tab's draft first, then the old copy goes.
        autosave.restored(draft.base_version);
        if (draft.client_id !== clientId) drafts.remove(note.id, draft.client_id).catch(() => {});
        if (draft.base_version === note.version) {
            show('restored');
        } else {
            autosave.conflict(note.version);
        }
    }
    setTitle();
    growTitle();
    loading = false;
    autosave.emit();

    // ---------- Title ----------
    titleField.addEventListener('input', () => {
        growTitle();
        setTitle();
        autosave.changed();
    });
    titleField.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            editor.commands.focus('start');
        }
    });
    window.addEventListener('resize', growTitle);

    // ---------- Toolbar: one tab stop, arrow keys move along it ----------
    function updateToolbar() {
        if (!editor) return;
        for (const button of buttons) {
            const isActive = COMMANDS[button.dataset.command][1];
            if (isActive) button.setAttribute('aria-pressed', String(isActive(editor)));
        }
        toolbar.querySelector('[data-command=undo]').disabled = !editor.can().undo();
        toolbar.querySelector('[data-command=redo]').disabled = !editor.can().redo();
    }
    toolbar.addEventListener('click', (event) => {
        const button = event.target.closest('button[data-command]');
        if (button) COMMANDS[button.dataset.command][0](editor.chain().focus()).run();
    });
    toolbar.addEventListener('keydown', (event) => {
        const enabled = buttons.filter((b) => !b.disabled);
        const at = enabled.indexOf(document.activeElement);
        const step = { ArrowRight: 1, ArrowLeft: -1, Home: -Infinity, End: Infinity }[event.key];
        if (at < 0 || step === undefined) return;
        event.preventDefault();
        const next = enabled[Math.max(0, Math.min(enabled.length - 1, Number.isFinite(step) ? (at + step + enabled.length) % enabled.length : (step < 0 ? 0 : enabled.length - 1)))];
        buttons.forEach((b) => { b.tabIndex = b === next ? 0 : -1; });
        next.focus();
    });
    updateToolbar();

    // ---------- Reading, and full screen ----------
    const page = document.querySelector('[data-note-page]');
    const focusButton = page.querySelector('[data-note-focus]');
    function setReading(on) {
        page.toggleAttribute('data-reading', on);
        editor.setEditable(!on, false);
        editor.view.dom.setAttribute('aria-readonly', String(on));
        titleField.readOnly = on;
        if (!on) editor.commands.focus();
    }
    function setFocus(on) {
        page.toggleAttribute('data-focus', on);
        // The whole screen where the browser allows it; the whole window everywhere.
        const root = document.documentElement;
        if (on && root.requestFullscreen && !document.fullscreenElement) root.requestFullscreen().catch(() => {});
        if (!on && document.fullscreenElement) document.exitFullscreen().catch(() => {});
        focusButton.focus();
    }
    page.querySelector('[data-note-read]').addEventListener('click', () => setReading(!page.hasAttribute('data-reading')));
    focusButton.addEventListener('click', () => setFocus(!page.hasAttribute('data-focus')));
    document.addEventListener('fullscreenchange', () => {
        if (!document.fullscreenElement && page.hasAttribute('data-focus')) setFocus(false);
    });
    document.addEventListener('keydown', (event) => {
        const busy = document.querySelector('dialog[open], [data-menu-panel]:not([hidden])');
        if (event.key === 'Escape' && page.hasAttribute('data-focus') && !document.fullscreenElement && !busy) setFocus(false);
    });

    // ---------- Other tabs ----------
    /** Shows a version from the server, without making it something undo would take back. */
    function showVersion(current) {
        loading = true;
        const at = editor.state.selection.from;
        editor.chain().setMeta('addToHistory', false).setContent(current.doc, { emitUpdate: false }).run();
        if (editor.isFocused) editor.commands.setTextSelection(Math.min(at, editor.state.doc.content.size - 1));
        titleField.value = current.title;
        loading = false;
        setTitle();
        growTitle();
    }
    async function fetchCurrent() {
        const response = await fetch(host.dataset.saveUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        return response.ok ? response.json() : null;
    }
    channel?.addEventListener('message', async ({ data }) => {
        if (data?.type === 'logout') {
            autosave.stop('session');
            return;
        }
        if (data?.type !== 'note-saved' || data.note !== note.id || data.client === clientId || data.version <= autosave.version()) return;
        if (!autosave.isClean()) {
            // Unsaved typing here: warn now, before this tab tries to save over it.
            autosave.conflict(data.version);
            return;
        }
        const current = await fetchCurrent();
        if (current && current.version > autosave.version() && autosave.isClean()) {
            showVersion(current);
            autosave.synced(current.version);
        }
    });

    // Logging out asks the open note to store what it holds first.
    window.addEventListener('vistud:store-drafts', (event) => event.detail.waitFor(autosave.storeNow()));

    // ---------- Choices in the alerts ----------
    alerts.addEventListener('click', async (event) => {
        const action = event.target.closest('[data-action]')?.dataset.action;
        if (action === 'keep-mine') {
            autosave.keepMine();
        } else if (action === 'keep-theirs') {
            const current = await fetchCurrent();
            if (!current) return;
            showVersion(current);
            autosave.keepTheirs(current.version);
            show(null);
        } else if (action === 'dismiss') {
            show(null);
        }
    });

    // ---------- Leaving ----------
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') autosave.flush(); });
    window.addEventListener('beforeunload', (event) => {
        if (autosave.atRisk()) event.preventDefault();
    });

    host.dataset.ready = 'true';
    return editor;
}
