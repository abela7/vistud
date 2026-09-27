/*
| The note editor (ADR 0003 §5, docs/specs/workspaces.md step 3). Tiptap, on
| a page element that no Livewire component renders. It restores a draft left
| in this browser, autosaves through PUT /api/v1/notes/{id}, and says honestly
| where the text is: on the server, only on this device, or at risk.
|
| A new note (the page has data-create-url) doesn't exist until it has a
| title or some text: its first save makes it (POST /api/v1/notes, with an ID
| of the editor's own so a retry can't make two), then the address becomes
| the note's and autosave takes over. Left empty, nothing is kept.
*/

import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import { TaskItem, TaskList } from '@tiptap/extension-list';
import { CharacterCount, Placeholder } from '@tiptap/extensions';
import { UniqueID } from '@tiptap/extension-unique-id';
import TextAlign from '@tiptap/extension-text-align';
import Highlight from '@tiptap/extension-highlight';
import Subscript from '@tiptap/extension-subscript';
import Superscript from '@tiptap/extension-superscript';
import { TableKit } from '@tiptap/extension-table';
import { deleteDrafts, openDrafts } from './drafts.js';
import { createAutosave, randomId, xsrf } from './autosave.js';

const BLOCKS = ['paragraph', 'heading', 'blockquote', 'bulletList', 'orderedList', 'taskList', 'codeBlock', 'horizontalRule', 'listItem', 'taskItem', 'table', 'tableRow', 'tableHeader', 'tableCell'];

/** A highlight keeps only a tone's name (app/Study/NoteDoc.php); the theme draws it. */
const NoteHighlight = Highlight.extend({
    addAttributes() {
        return {
            tone: {
                default: null,
                parseHTML: (el) => el.getAttribute('data-tone'),
                renderHTML: (attrs) => (attrs.tone ? { 'data-tone': attrs.tone } : {}),
            },
        };
    },
});
const HIGHLIGHT = NoteHighlight.name;

const LABELS = {
    draft: 'Not saved yet',
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
    underline: [(c) => c.toggleUnderline(), (e) => e.isActive('underline')],
    strike: [(c) => c.toggleStrike(), (e) => e.isActive('strike')],
    superscript: [(c) => c.toggleSuperscript(), (e) => e.isActive('superscript')],
    subscript: [(c) => c.toggleSubscript(), (e) => e.isActive('subscript')],
    code: [(c) => c.toggleCode(), (e) => e.isActive('code')],
    table: [(c) => c.insertTable({ rows: 3, cols: 3, withHeaderRow: true }), (e) => e.isActive('table')],
    clear: [(c) => c.unsetAllMarks().clearNodes(), null],
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
    let toolbarReady = false;
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
            TextAlign.configure({ types: ['heading', 'paragraph'], alignments: ['left', 'center', 'right', 'justify'] }),
            NoteHighlight,
            Subscript,
            Superscript,
            TableKit.configure({ table: { resizable: false } }),
            CharacterCount,
            Placeholder.configure({ placeholder: 'Start writing…' }),
            UniqueID.configure({ types: BLOCKS, generateID: () => randomId(8) }),
        ],
        editorProps: {
            attributes: { class: 'note-prose', 'aria-label': 'Note', 'aria-multiline': 'true', role: 'textbox' },
        },
        onUpdate: () => { if (!loading) changed(); },
        onTransaction: () => updateToolbar(),
    });

    // Tabs of this account tell each other what they saved (ADR 0003 §5.3). Other accounts' tabs never hear it.
    const channel = 'BroadcastChannel' in window ? new BroadcastChannel(`vistud-${accountId}`) : null;

    const setStatus = (state) => {
        status.dataset.state = state;
        status.querySelector('[data-save-label]').textContent = LABELS[state].replace('{s}', '');
    };
    function startAutosave() {
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
    }

    // ---------- A new note: made by its first words ----------
    const hasWords = () => titleField.value.trim() !== '' || editor.getText().trim() !== '';
    const createId = randomId(16);
    let creating = false;
    let createTimer = null;
    let createAttempt = 0;
    function changed() {
        if (autosave) {
            autosave.changed();
            return;
        }
        clearTimeout(createTimer);
        if (!hasWords()) {
            if (!creating) setStatus('draft');
            return;
        }
        createTimer = setTimeout(createNote, 600);
    }
    async function createNote() {
        clearTimeout(createTimer);
        if (autosave || creating || !hasWords()) return;
        creating = true;
        setStatus('saving');
        const sent = { title: titleField.value, doc: editor.getJSON() };
        let response = null;
        let data = null;
        try {
            response = await fetch(host.dataset.createUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf() },
                body: JSON.stringify({ place: { type: host.dataset.placeType, id: host.dataset.placeId }, create_id: createId, ...sent }),
            });
            data = await response.json().catch(() => null);
        } catch {
            response = null;
        }
        creating = false;
        if (response?.ok && data?.id) {
            [note.id, note.version] = [data.id, data.version];
            host.dataset.saveUrl = data.save_url;
            delete host.dataset.createUrl;
            history.replaceState(history.state, '', data.url);
            startAutosave();
            // Whatever was typed while it was being made is saved next.
            if (JSON.stringify(sent) !== JSON.stringify({ title: titleField.value, doc: editor.getJSON() })) autosave.changed();
            else autosave.emit();
            return;
        }
        const code = response?.status;
        if (code === 401 || code === 419) {
            setStatus('session');
            show('session');
        } else if (code === 422 || code === 413 || code === 404) {
            if (!hasWords()) {
                setStatus('draft');
                return;
            }
            setStatus('rejected');
            alerts.querySelector('[data-rejected-message]').textContent = Object.values(data?.error?.details?.fields ?? {})[0]?.[0] ?? 'This note can\'t be saved as it is.';
            show('rejected');
        } else {
            // Offline or the server didn't answer: the same first save again, a little later.
            const wait = [2, 5, 15, 30, 60][Math.min(createAttempt++, 4)];
            setStatus('retrying');
            status.querySelector('[data-save-label]').textContent = LABELS.retrying.replace('{s}', wait);
            createTimer = setTimeout(createNote, wait * 1000);
        }
    }

    // ---------- A draft left in this browser ----------
    if (note.id) startAutosave();
    const [draft] = note.id && drafts ? await drafts.forNote(note.id).catch(() => []) : [];
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
    if (autosave) autosave.emit();
    else setStatus('draft');

    // ---------- Title ----------
    titleField.addEventListener('input', () => {
        growTitle();
        setTitle();
        changed();
    });
    titleField.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            editor.commands.focus('start');
        }
    });
    window.addEventListener('resize', growTitle);

    // ---------- Toolbar: one tab stop, arrow keys move along it ----------
    const page = document.querySelector('[data-note-page]');
    const items = [...toolbar.querySelectorAll('button, select')];
    const blockStyle = toolbar.querySelector('[data-block-style]');
    const highlightMenu = host.querySelector('#note-highlight-menu');
    const alignMenu = host.querySelector('#note-align-menu');
    const linkBar = host.querySelector('[data-link-bar]');
    const linkInput = linkBar.querySelector('[data-link-input]');
    const tableBar = host.querySelector('[data-table-bar]');
    const count = host.querySelector('[data-note-count]');
    const alignOf = () => ['center', 'right', 'justify'].find((a) => editor.isActive({ textAlign: a })) ?? 'left';

    function updateToolbar() {
        if (!editor || !toolbarReady) return;
        for (const button of buttons) {
            const isActive = COMMANDS[button.dataset.command]?.[1];
            if (isActive) button.setAttribute('aria-pressed', String(isActive(editor)));
        }
        toolbar.querySelector('[data-command=link]').setAttribute('aria-pressed', String(editor.isActive('link')));
        toolbar.querySelector('[data-command=undo]').disabled = !editor.can().undo();
        toolbar.querySelector('[data-command=redo]').disabled = !editor.can().redo();
        const level = [1, 2, 3].find((l) => editor.isActive('heading', { level: l }));
        blockStyle.value = level ? String(level) : 'paragraph';
        const align = alignOf();
        toolbar.querySelectorAll('[data-align-icon]').forEach((icon) => { icon.toggleAttribute('hidden', icon.dataset.alignIcon !== align); });
        alignMenu.querySelectorAll('[data-align]').forEach((item) => item.setAttribute('aria-checked', String(item.dataset.align === align)));
        const tone = editor.isActive(HIGHLIGHT) ? (editor.getAttributes(HIGHLIGHT).tone ?? highlightMenu.querySelector('[role=menuitemradio]').dataset.highlight) : null;
        highlightMenu.querySelectorAll('[role=menuitemradio]').forEach((item) => item.setAttribute('aria-checked', String(item.dataset.highlight === tone)));
        tableBar.hidden = !editor.isActive('table') || page.hasAttribute('data-reading');
        const words = editor.storage.characterCount.words();
        count.textContent = `${words} ${words === 1 ? 'word' : 'words'} · ${editor.storage.characterCount.characters()} characters`;
    }
    toolbar.addEventListener('click', (event) => {
        const button = event.target.closest('button[data-command]');
        if (!button) return;
        if (button.dataset.command === 'link') {
            toggleLinkBar(linkBar.hidden);
            return;
        }
        COMMANDS[button.dataset.command][0](editor.chain().focus()).run();
    });
    blockStyle.addEventListener('change', () => {
        const chain = editor.chain().focus();
        (blockStyle.value === 'paragraph' ? chain.setParagraph() : chain.setHeading({ level: Number(blockStyle.value) })).run();
    });
    toolbar.addEventListener('keydown', (event) => {
        const enabled = items.filter((b) => !b.disabled);
        const at = enabled.indexOf(document.activeElement);
        const step = { ArrowRight: 1, ArrowLeft: -1, Home: -Infinity, End: Infinity }[event.key];
        if (at < 0 || step === undefined) return;
        // Left and right inside the select change nothing on most browsers; they move along the toolbar.
        event.preventDefault();
        const next = enabled[Math.max(0, Math.min(enabled.length - 1, Number.isFinite(step) ? (at + step + enabled.length) % enabled.length : (step < 0 ? 0 : enabled.length - 1)))];
        items.forEach((b) => { b.tabIndex = b === next ? 0 : -1; });
        next.focus();
    });

    // The menus (highlight colours, alignment) open under their button and give focus to their first choice.
    for (const menu of [highlightMenu, alignMenu]) {
        const opener = toolbar.querySelector(`[data-menu-for="${menu.id}"]`);
        menu.addEventListener('toggle', (event) => {
            opener.setAttribute('aria-expanded', String(event.newState === 'open'));
            if (event.newState !== 'open') return;
            const box = opener.getBoundingClientRect();
            menu.style.top = `${box.bottom + 4}px`;
            menu.style.left = `${Math.max(8, Math.min(box.left, window.innerWidth - menu.offsetWidth - 8))}px`;
            (menu.querySelector('[aria-checked="true"]') ?? menu.querySelector('button'))?.focus();
        });
        menu.addEventListener('keydown', (event) => {
            const choices = [...menu.querySelectorAll('button')];
            const at = choices.indexOf(document.activeElement);
            const step = { ArrowDown: 1, ArrowUp: -1 }[event.key];
            if (step) {
                event.preventDefault();
                choices[(at + step + choices.length) % choices.length].focus();
            }
        });
    }
    highlightMenu.addEventListener('click', (event) => {
        const item = event.target.closest('[data-highlight]');
        if (!item) return;
        // Closed first: closing hands focus back to its button, and the writing goes on in the note.
        highlightMenu.hidePopover();
        const chain = editor.chain().focus();
        (item.dataset.highlight === '' ? chain.unsetHighlight() : chain.setHighlight({ tone: item.dataset.highlight })).run();
    });
    alignMenu.addEventListener('click', (event) => {
        const item = event.target.closest('[data-align]');
        if (!item) return;
        alignMenu.hidePopover();
        editor.chain().focus().setTextAlign(item.dataset.align).run();
    });

    // A link: its address in the bar under the toolbar. An address without a scheme gets https://.
    function toggleLinkBar(open) {
        linkBar.hidden = !open;
        toolbar.querySelector('[data-command=link]').setAttribute('aria-expanded', String(open));
        if (open) {
            linkInput.value = editor.getAttributes('link').href ?? '';
            linkInput.focus();
            linkInput.select();
        }
    }
    function applyLink() {
        const typed = linkInput.value.trim();
        const href = typed === '' || /^(https?:\/\/|mailto:)/i.test(typed) ? typed : `https://${typed}`;
        const chain = editor.chain().focus().extendMarkRange('link');
        (href === '' ? chain.unsetLink() : chain.setLink({ href })).run();
        toggleLinkBar(false);
    }
    linkBar.querySelector('[data-link-apply]').addEventListener('click', applyLink);
    linkBar.querySelector('[data-link-remove]').addEventListener('click', () => {
        editor.chain().focus().extendMarkRange('link').unsetLink().run();
        toggleLinkBar(false);
    });
    linkInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            applyLink();
        } else if (event.key === 'Escape') {
            event.preventDefault();
            toggleLinkBar(false);
            editor.commands.focus();
        }
    });
    host.querySelector('[data-note-body]').addEventListener('keydown', (event) => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            toggleLinkBar(true);
        }
    });

    // In a table: rows and columns.
    tableBar.addEventListener('click', (event) => {
        const command = event.target.closest('[data-table-command]')?.dataset.tableCommand;
        if (command) editor.chain().focus()[command]().run();
    });
    toolbarReady = true;
    updateToolbar();

    // ---------- Reading, and full screen ----------
    const focusButton = page.querySelector('[data-note-focus]');
    function setReading(on) {
        page.toggleAttribute('data-reading', on);
        if (on) toggleLinkBar(false);
        updateToolbar();
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
            autosave?.stop('session');
            return;
        }
        if (!autosave || data?.type !== 'note-saved' || data.note !== note.id || data.client === clientId || data.version <= autosave.version()) return;
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
    window.addEventListener('vistud:store-drafts', (event) => event.detail.waitFor(autosave ? autosave.storeNow() : Promise.resolve()));

    // ---------- Choices in the alerts ----------
    alerts.addEventListener('click', async (event) => {
        const action = event.target.closest('[data-action]')?.dataset.action;
        if (!autosave && action !== 'dismiss') return;
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
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState !== 'hidden') return;
        if (autosave) autosave.flush();
        else if (hasWords()) createNote();
    });
    window.addEventListener('beforeunload', (event) => {
        if (autosave ? autosave.atRisk() : hasWords()) event.preventDefault();
    });

    host.dataset.ready = 'true';
    return editor;
}
