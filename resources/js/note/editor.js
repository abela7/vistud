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

import { Editor, Node, mergeAttributes } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import { TaskItem, TaskList } from '@tiptap/extension-list';
import { CharacterCount, Placeholder } from '@tiptap/extensions';
import { UniqueID } from '@tiptap/extension-unique-id';
import TextAlign from '@tiptap/extension-text-align';
import Highlight from '@tiptap/extension-highlight';
import Subscript from '@tiptap/extension-subscript';
import Superscript from '@tiptap/extension-superscript';
import Underline from '@tiptap/extension-underline';
import { TableKit } from '@tiptap/extension-table';
import { TextSelection } from '@tiptap/pm/state';
import { canSplit } from '@tiptap/pm/transform';
import { deleteDrafts, openDrafts } from './drafts.js';
import { createAutosave, randomId, xsrf } from './autosave.js';

const BLOCKS = ['paragraph', 'heading', 'blockquote', 'bulletList', 'orderedList', 'taskList', 'codeBlock', 'horizontalRule', 'listItem', 'taskItem', 'table', 'tableRow', 'tableHeader', 'tableCell', 'callout', 'image', 'pageBreak'];

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

/** A STEM callout card (Theorem, Definition, Key Formula, Example, Note). */
const NoteCallout = Node.create({
    name: 'callout',
    group: 'block',
    content: 'block+',
    defining: true,
    addAttributes() {
        return {
            tone: {
                default: 'note',
                parseHTML: (el) => el.getAttribute('data-tone') || 'note',
                renderHTML: (attrs) => ({ 'data-tone': attrs.tone }),
            },
        };
    },
    parseHTML() {
        return [{ tag: 'div[data-callout]' }];
    },
    renderHTML({ HTMLAttributes }) {
        return ['div', mergeAttributes({ 'data-callout': '' }, HTMLAttributes), 0];
    },
});

/** An image block with size, alignment and caption attributes. */
const NoteImage = Node.create({
    name: 'image',
    group: 'block',
    defining: true,
    draggable: true,
    selectable: true,

    addAttributes() {
        return {
            src: {
                default: null,
                parseHTML: (el) => el.querySelector('img')?.getAttribute('src') || el.getAttribute('src'),
                renderHTML: (attrs) => ({ src: attrs.src }),
            },
            alt: {
                default: '',
                parseHTML: (el) => el.querySelector('img')?.getAttribute('alt') || el.getAttribute('alt') || '',
                renderHTML: (attrs) => (attrs.alt ? { alt: attrs.alt } : {}),
            },
            title: {
                default: '',
                parseHTML: (el) => el.querySelector('figcaption')?.textContent || el.getAttribute('title') || '',
                renderHTML: (attrs) => (attrs.title ? { title: attrs.title } : {}),
            },
            width: {
                default: '100%',
                parseHTML: (el) => el.getAttribute('data-width') || '100%',
                renderHTML: (attrs) => ({ 'data-width': attrs.width || '100%' }),
            },
            align: {
                default: 'center',
                parseHTML: (el) => el.getAttribute('data-align') || 'center',
                renderHTML: (attrs) => ({ 'data-align': attrs.align || 'center' }),
            },
        };
    },

    parseHTML() {
        return [
            { tag: 'figure[data-note-image]' },
            { tag: 'img[src]' },
        ];
    },

    renderHTML({ HTMLAttributes }) {
        const { src, alt, title, 'data-width': width, 'data-align': align } = HTMLAttributes;
        const figAttrs = {
            'data-note-image': '',
            'data-width': width || '100%',
            'data-align': align || 'center',
            class: 'note-image-figure',
        };
        const imgAttrs = {
            src,
            alt: alt || '',
            loading: 'lazy',
            class: 'note-image-img',
        };
        const children = [
            ['div', { class: 'note-image-wrapper' },
                ['img', imgAttrs],
                ['span', { class: 'note-image-resize-handle', 'aria-hidden': 'true' }],
            ],
        ];
        if (title) {
            children.push(['figcaption', { class: 'note-image-caption' }, title]);
        }
        return ['figure', figAttrs, ...children];
    },
});

/** An MS Word-style Page Break block node. */
const NotePageBreak = Node.create({
    name: 'pageBreak',
    group: 'block',
    selectable: true,
    draggable: true,
    defining: true,

    parseHTML() {
        return [
            { tag: 'div[data-page-break]' },
            { tag: 'div.note-page-break' },
            { tag: 'div.page-break' },
        ];
    },

    renderHTML({ HTMLAttributes }) {
        return [
            'div',
            mergeAttributes({ 'data-page-break': '', class: 'note-page-break' }, HTMLAttributes),
            ['span', { class: 'page-break-line', 'aria-hidden': 'true' }],
            ['span', { class: 'page-break-badge' }, 'Page Break'],
            ['span', { class: 'page-break-line', 'aria-hidden': 'true' }],
        ];
    },

    addCommands() {
        return {
            setPageBreak: () => ({ chain }) => chain().insertContent({ type: 'pageBreak' }).run(),
        };
    },

    addKeyboardShortcuts() {
        return {
            'Mod-Enter': () => this.editor.commands.setPageBreak(),
        };
    },
});

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
    pageBreak: [(c) => c.insertContent({ type: 'pageBreak' }), null],
    indent: [
        (c) => c.command(({ commands }) => {
            if (commands.sinkListItem('listItem')) return true;
            if (commands.sinkListItem('taskItem')) return true;
            return commands.insertContent('    ');
        }),
        null,
    ],
    outdent: [
        (c) => c.command(({ commands }) => {
            if (commands.liftListItem('listItem')) return true;
            if (commands.liftListItem('taskItem')) return true;
            return true;
        }),
        null,
    ],
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

/**
 * When the user selects a partial substring inside a block and applies a block-level
 * formatting command (Title, Heading, Blockquote, CodeBlock, Lists, Clear formatting),
 * ProseMirror by default applies the block command to the whole enclosing parent block.
 *
 * This isolates the selection by splitting the enclosing block at the boundaries of the
 * selection (if not already at the boundary), and reselects the newly isolated block
 * so subsequent block commands only affect the selected text.
 */
function isolateSelection(editor) {
    if (!editor) return false;
    const { state, view } = editor;
    const { selection } = state;
    if (!selection || selection.empty) return false;

    const { $from, $to, from, to } = selection;
    if (!$from || !$to) return false;

    const isAtStart = $from.parentOffset === 0;
    const isAtEnd = $to.parentOffset === $to.parent.content.size;
    if (isAtStart && isAtEnd) return false;

    const tr = state.tr;
    let didSplit = false;

    // 1. Split after selection if not at block end
    if (!isAtEnd && canSplit(tr.doc, to, 1)) {
        tr.split(to, 1);
        didSplit = true;
    }

    // 2. Split before selection if not at block start
    // Splitting at `to` only inserts tokens at/after `to`, so `from` is unaltered in tr.doc.
    if (!isAtStart && canSplit(tr.doc, from, 1)) {
        tr.split(from, 1);
        didSplit = true;
    }

    if (!didSplit) return false;

    // Map selection to the newly isolated block
    const newFrom = tr.mapping.map(from, 1);
    const newTo = tr.mapping.map(to, -1);
    tr.setSelection(TextSelection.create(tr.doc, newFrom, newTo));
    view.dispatch(tr);
    return true;
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

    async function uploadImageFile(file) {
        if (!file) return null;
        const formData = new FormData();
        formData.append('image', file);
        try {
            const res = await fetch(host.dataset.imageUploadUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': xsrf(),
                },
                body: formData,
            });
            if (res.ok) {
                const data = await res.json();
                return data.url;
            }
        } catch {}
        return new Promise((resolve) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result);
            reader.readAsDataURL(file);
        });
    }

    function insertImage({ src, alt = '', title = '', width = '100%', align = 'center' }) {
        if (!src) return;
        editor.chain().focus().insertContent({
            type: 'image',
            attrs: { src, alt, title, width, align },
        }).run();
    }

    editor = new Editor({
        element: host.querySelector('[data-note-body]'),
        // Its default styles hardcode colours; resources/css/editor.css has themed ones.
        injectCSS: false,
        content: note.doc,
        extensions: [
            StarterKit.configure({
                heading: { levels: [1, 2, 3, 4] },
                dropcursor: { color: 'var(--drop-indicator)', width: 2 },
                link: { openOnClick: false, autolink: true, protocols: [], defaultProtocol: 'https', isAllowedUri: (url) => /^(https?:\/\/|mailto:)/i.test(url) },
            }),
            TaskList,
            TaskItem.configure({ nested: true }),
            TextAlign.configure({ types: ['heading', 'paragraph'], alignments: ['left', 'center', 'right', 'justify'] }),
            NoteHighlight,
            NoteCallout,
            NoteImage,
            NotePageBreak,
            Subscript,
            Superscript,
            Underline,
            TableKit.configure({ table: { resizable: false } }),
            CharacterCount,
            Placeholder.configure({ placeholder: 'Start writing…' }),
            UniqueID.configure({ types: BLOCKS, generateID: () => randomId(8) }),
        ],
        editorProps: {
            attributes: { class: 'note-prose', 'aria-label': 'Note', 'aria-multiline': 'true', role: 'textbox' },
            handlePaste: (view, event) => {
                const items = Array.from(event.clipboardData?.items || []);
                const imageItem = items.find((item) => item.type.startsWith('image/'));
                if (imageItem) {
                    const file = imageItem.getAsFile();
                    if (file) {
                        event.preventDefault();
                        uploadImageFile(file).then((url) => {
                            if (url) insertImage({ src: url, alt: file.name ? file.name.replace(/\.[^.]+$/, '') : 'Pasted image' });
                        });
                        return true;
                    }
                }
                return false;
            },
            handleDrop: (view, event) => {
                const files = Array.from(event.dataTransfer?.files || []);
                const imageFile = files.find((file) => file.type.startsWith('image/'));
                if (imageFile) {
                    event.preventDefault();
                    const coordinates = view.posAtCoords({ left: event.clientX, top: event.clientY });
                    uploadImageFile(imageFile).then((url) => {
                        if (url) {
                            const node = editor.schema.nodes.image.create({
                                src: url,
                                alt: imageFile.name.replace(/\.[^.]+$/, ''),
                                width: '100%',
                                align: 'center',
                            });
                            const tr = view.state.tr.insert(coordinates ? coordinates.pos : view.state.selection.from, node);
                            view.dispatch(tr);
                        }
                    });
                    return true;
                }
                return false;
            },
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
            try {
                const draftStyle = localStorage.getItem('vistud.title-style.draft');
                if (draftStyle) {
                    localStorage.setItem(`vistud.title-style.${data.id}`, draftStyle);
                    localStorage.removeItem('vistud.title-style.draft');
                }
            } catch {}
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

    function saveNow() {
        if (autosave) {
            autosave.flush();
            if (autosave.isClean()) {
                setStatus('saved');
            }
        } else if (hasWords()) {
            createNote();
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
    const titleStyleKey = () => `vistud.title-style.${note.id || 'draft'}`;
    const loadTitleStyle = () => {
        try {
            const saved = JSON.parse(localStorage.getItem(titleStyleKey()) || '{}');
            if (saved.align) titleField.dataset.align = saved.align;
            if (saved.italic) titleField.dataset.italic = 'true';
            if (saved.underline) titleField.dataset.underline = 'true';
            if (saved.tone) titleField.dataset.tone = saved.tone;
        } catch {}
    };
    const saveTitleStyle = () => {
        try {
            const style = {
                align: titleField.dataset.align || 'left',
                italic: titleField.dataset.italic === 'true',
                underline: titleField.dataset.underline === 'true',
                tone: titleField.dataset.tone || '',
            };
            localStorage.setItem(titleStyleKey(), JSON.stringify(style));
        } catch {}
    };
    loadTitleStyle();

    titleField.addEventListener('input', () => {
        growTitle();
        setTitle();
        changed();
    });
    titleField.addEventListener('keydown', (event) => {
        const isMod = event.ctrlKey || event.metaKey;
        if (event.key === 'Enter') {
            event.preventDefault();
            editor.commands.focus('start');
            return;
        }
        if (isMod && !event.shiftKey && !event.altKey) {
            const key = event.key.toLowerCase();
            if (key === 'i') {
                event.preventDefault();
                titleField.dataset.italic = titleField.dataset.italic === 'true' ? 'false' : 'true';
                saveTitleStyle();
                updateToolbar();
                return;
            }
            if (key === 'u') {
                event.preventDefault();
                titleField.dataset.underline = titleField.dataset.underline === 'true' ? 'false' : 'true';
                saveTitleStyle();
                updateToolbar();
                return;
            }
            if (key === 'e') {
                event.preventDefault();
                titleField.dataset.align = 'center';
                saveTitleStyle();
                updateToolbar();
                return;
            }
            if (key === 'l') {
                event.preventDefault();
                titleField.dataset.align = 'left';
                saveTitleStyle();
                updateToolbar();
                return;
            }
            if (key === 'r') {
                event.preventDefault();
                titleField.dataset.align = 'right';
                saveTitleStyle();
                updateToolbar();
                return;
            }
            if (key === 's') {
                event.preventDefault();
                saveNow();
                return;
            }
        }
    });
    window.addEventListener('resize', growTitle);

    // ---------- Toolbar: one tab stop, arrow keys move along it ----------
    const page = document.querySelector('[data-note-page]');
    const items = [...toolbar.querySelectorAll('button, select')];
    const blockStyle = toolbar.querySelector('[data-block-style]');
    const highlightMenu = host.querySelector('#note-highlight-menu');
    const alignMenu = host.querySelector('#note-align-menu');
    const calloutMenu = host.querySelector('#note-callout-menu');
    const mathMenu = host.querySelector('#note-math-menu');
    const linkBar = host.querySelector('[data-link-bar]');
    const linkInput = linkBar.querySelector('[data-link-input]');
    const tableBar = host.querySelector('[data-table-bar]');
    const findBar = host.querySelector('[data-find-bar]');
    const findInput = findBar?.querySelector('[data-find-input]');
    const findCount = findBar?.querySelector('[data-find-count]');
    const findPrev = findBar?.querySelector('[data-find-prev]');
    const findNext = findBar?.querySelector('[data-find-next]');
    const replaceGroup = findBar?.querySelector('[data-replace-group]');
    const replaceInput = findBar?.querySelector('[data-replace-input]');
    const replaceOneBtn = findBar?.querySelector('[data-replace-one]');
    const replaceAllBtn = findBar?.querySelector('[data-replace-all]');
    const toggleReplaceBtn = findBar?.querySelector('[data-toggle-replace]');
    const findCloseBtn = findBar?.querySelector('[data-find-close]');
    const statsDialog = host.querySelector('[data-stats-dialog]');
    const shortcutsDialog = host.querySelector('[data-shortcuts-dialog]');
    const imageDialog = host.querySelector('#note-image-dialog');
    const imageToolbar = host.querySelector('#note-image-toolbar');
    const count = host.querySelector('[data-note-count]');
    const alignOf = () => ['center', 'right', 'justify'].find((a) => editor.isActive({ textAlign: a })) ?? 'left';

    let lastFocused = 'editor';
    titleField.addEventListener('focus', () => {
        lastFocused = 'title';
        updateToolbar();
    });
    host.querySelector('[data-note-body]').addEventListener('focusin', () => {
        lastFocused = 'editor';
        updateToolbar();
    });

    // Don't let buttons steal focus on mousedown, so selection in the editor or title is kept.
    toolbar.addEventListener('mousedown', (event) => {
        if (event.target.closest('button')) {
            event.preventDefault();
        }
    });

    function getPageStats() {
        let pageBreakPositions = [];
        editor.state.doc.descendants((node, pos) => {
            if (node.type.name === 'pageBreak') {
                pageBreakPositions.push(pos);
            }
        });

        const cursorPos = editor.state.selection.from;
        const words = editor.storage.characterCount.words();
        const chars = editor.storage.characterCount.characters();

        let totalPages = 1;
        let currentPage = 1;

        if (pageBreakPositions.length > 0) {
            totalPages = pageBreakPositions.length + 1;
            currentPage = pageBreakPositions.filter((p) => cursorPos > p).length + 1;
        } else {
            const bodyEl = host.querySelector('[data-note-body]');
            const bodyHeight = bodyEl ? bodyEl.offsetHeight : 0;
            totalPages = Math.max(1, Math.ceil(bodyHeight / 950));

            const scrollContainer = host.querySelector('.note-content');
            if (scrollContainer && scrollContainer.scrollHeight > scrollContainer.clientHeight) {
                const scrollRatio = scrollContainer.scrollTop / (scrollContainer.scrollHeight - scrollContainer.clientHeight);
                currentPage = Math.min(totalPages, Math.max(1, Math.floor(scrollRatio * totalPages) + 1));
            } else {
                currentPage = 1;
            }
        }

        return { currentPage, totalPages, words, chars };
    }

    function updateToolbar() {
        if (!editor || !toolbarReady) return;
        const inTitle = lastFocused === 'title';
        for (const button of buttons) {
            const cmd = button.dataset.command;
            if (inTitle) {
                if (cmd === 'bold') button.setAttribute('aria-pressed', 'true');
                else if (cmd === 'italic') button.setAttribute('aria-pressed', String(titleField.dataset.italic === 'true'));
                else if (cmd === 'underline') button.setAttribute('aria-pressed', String(titleField.dataset.underline === 'true'));
                else {
                    const isActive = COMMANDS[cmd]?.[1];
                    if (isActive) button.setAttribute('aria-pressed', 'false');
                }
            } else {
                const isActive = COMMANDS[cmd]?.[1];
                if (isActive) button.setAttribute('aria-pressed', String(isActive(editor)));
            }
        }
        toolbar.querySelector('[data-command=link]').setAttribute('aria-pressed', inTitle ? 'false' : String(editor.isActive('link')));
        toolbar.querySelector('[data-command=undo]').disabled = inTitle ? true : !editor.can().undo();
        toolbar.querySelector('[data-command=redo]').disabled = inTitle ? true : !editor.can().redo();
        if (inTitle) {
            blockStyle.value = '1';
            const align = titleField.dataset.align || 'left';
            toolbar.querySelectorAll('[data-align-icon]').forEach((icon) => { icon.toggleAttribute('hidden', icon.dataset.alignIcon !== align); });
            alignMenu.querySelectorAll('[data-align]').forEach((item) => item.setAttribute('aria-checked', String(item.dataset.align === align)));
            const tone = titleField.dataset.tone || null;
            highlightMenu.querySelectorAll('[role=menuitemradio]').forEach((item) => item.setAttribute('aria-checked', String(item.dataset.highlight === tone)));
        } else {
            const level = [1, 2, 3, 4].find((l) => editor.isActive('heading', { level: l }));
            blockStyle.value = level ? String(level) : 'paragraph';
            const align = alignOf();
            toolbar.querySelectorAll('[data-align-icon]').forEach((icon) => { icon.toggleAttribute('hidden', icon.dataset.alignIcon !== align); });
            alignMenu.querySelectorAll('[data-align]').forEach((item) => item.setAttribute('aria-checked', String(item.dataset.align === align)));
            const tone = editor.isActive(HIGHLIGHT) ? (editor.getAttributes(HIGHLIGHT).tone ?? highlightMenu.querySelector('[role=menuitemradio]').dataset.highlight) : null;
            highlightMenu.querySelectorAll('[role=menuitemradio]').forEach((item) => item.setAttribute('aria-checked', String(item.dataset.highlight === tone)));
        }
        tableBar.hidden = !editor.isActive('table') || page.hasAttribute('data-reading');
        const { currentPage, totalPages, words, chars } = getPageStats();
        count.textContent = `Page ${currentPage} of ${totalPages} · ${words} ${words === 1 ? 'word' : 'words'} · ${chars.toLocaleString()} characters`;
    }
    toolbar.addEventListener('click', (event) => {
        const button = event.target.closest('button[data-command]');
        if (!button) return;
        const cmd = button.dataset.command;
        if (cmd === 'link') {
            toggleLinkBar(linkBar.hidden);
            return;
        }
        if (cmd === 'image') {
            imageDialog?.showModal();
            return;
        }
        if (cmd === 'find') {
            toggleFindBar(findBar.hidden);
            return;
        }
        if (cmd === 'shortcuts') {
            shortcutsDialog?.showModal();
            return;
        }
        if (lastFocused === 'title') {
            if (cmd === 'italic') {
                titleField.dataset.italic = titleField.dataset.italic === 'true' ? 'false' : 'true';
                saveTitleStyle();
                updateToolbar();
                titleField.focus();
                return;
            }
            if (cmd === 'underline') {
                titleField.dataset.underline = titleField.dataset.underline === 'true' ? 'false' : 'true';
                saveTitleStyle();
                updateToolbar();
                titleField.focus();
                return;
            }
            if (cmd === 'indent') {
                titleField.setRangeText('    ', titleField.selectionStart, titleField.selectionEnd, 'end');
                titleField.dispatchEvent(new Event('input'));
                titleField.focus();
                return;
            }
            if (cmd === 'clear') {
                delete titleField.dataset.align;
                delete titleField.dataset.italic;
                delete titleField.dataset.underline;
                delete titleField.dataset.tone;
                saveTitleStyle();
                updateToolbar();
                titleField.focus();
                return;
            }
        }
        const BLOCK_COMMANDS = new Set(['blockquote', 'codeBlock', 'bulletList', 'orderedList', 'taskList', 'clear']);
        if (COMMANDS[cmd]) {
            if (BLOCK_COMMANDS.has(cmd)) {
                isolateSelection(editor);
            }
            COMMANDS[cmd][0](editor.chain().focus()).run();
        }
    });
    blockStyle.addEventListener('change', () => {
        lastFocused = 'editor';
        isolateSelection(editor);
        const chain = editor.chain().focus();
        (blockStyle.value === 'paragraph' ? chain.setParagraph() : chain.setHeading({ level: Number(blockStyle.value) })).run();
        updateToolbar();
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

    // The menus (highlight colours, alignment, callouts, math) open under their button.
    for (const menu of [highlightMenu, alignMenu, calloutMenu, mathMenu].filter(Boolean)) {
        const opener = toolbar.querySelector(`[data-menu-for="${menu.id}"]`);
        if (!opener) continue;
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
            const step = { ArrowDown: 1, ArrowUp: -1, ArrowRight: 1, ArrowLeft: -1 }[event.key];
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
        if (lastFocused === 'title') {
            if (item.dataset.highlight) titleField.dataset.tone = item.dataset.highlight;
            else delete titleField.dataset.tone;
            saveTitleStyle();
            updateToolbar();
            titleField.focus();
            return;
        }
        const chain = editor.chain().focus();
        (item.dataset.highlight === '' ? chain.unsetHighlight() : chain.setHighlight({ tone: item.dataset.highlight })).run();
    });
    alignMenu.addEventListener('click', (event) => {
        const item = event.target.closest('[data-align]');
        if (!item) return;
        alignMenu.hidePopover();
        if (lastFocused === 'title') {
            titleField.dataset.align = item.dataset.align;
            saveTitleStyle();
            updateToolbar();
            titleField.focus();
            return;
        }
        editor.chain().focus().setTextAlign(item.dataset.align).run();
    });
    if (calloutMenu) {
        calloutMenu.addEventListener('click', (event) => {
            const item = event.target.closest('[data-callout-tone]');
            if (!item) return;
            calloutMenu.hidePopover();
            let calloutContent = [{ type: 'paragraph' }];
            const { selection, doc } = editor.state;
            if (selection && !selection.empty) {
                const slice = doc.slice(selection.from, selection.to);
                const json = slice.content.toJSON();
                if (json && json.length > 0) {
                    if (json[0].type === 'text') {
                        calloutContent = [{ type: 'paragraph', content: json }];
                    } else {
                        calloutContent = json;
                    }
                }
            }
            editor.chain().focus().insertContent({
                type: 'callout',
                attrs: { tone: item.dataset.calloutTone },
                content: calloutContent,
            }).run();
        });
    }
    if (mathMenu) {
        mathMenu.addEventListener('click', (event) => {
            const item = event.target.closest('[data-insert-text]');
            if (!item) return;
            mathMenu.hidePopover();
            const text = item.dataset.insertText;
            if (lastFocused === 'title') {
                titleField.setRangeText(text, titleField.selectionStart, titleField.selectionEnd, 'end');
                titleField.dispatchEvent(new Event('input'));
                titleField.focus();
                return;
            }
            editor.chain().focus().insertContent(text).run();
        });
    }

    // A link: its address in the bar under the toolbar. An address without a scheme gets https://.
    function toggleLinkBar(open) {
        linkBar.hidden = !open;
        toolbar.querySelector('[data-command=link]').setAttribute('aria-expanded', String(open));
        if (open) {
            toggleFindBar(false);
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

    // ---------- Find & Replace ----------
    let matches = [];
    let currentMatchIndex = 0;

    function getMatches(query) {
        if (!query) return [];
        const found = [];
        const lower = query.toLowerCase();
        editor.state.doc.descendants((node, pos) => {
            if (node.isText) {
                const text = node.text;
                const lowerText = text.toLowerCase();
                let idx = 0;
                while ((idx = lowerText.indexOf(lower, idx)) !== -1) {
                    found.push({
                        from: pos + idx,
                        to: pos + idx + query.length,
                    });
                    idx += query.length;
                }
            }
        });
        return found;
    }

    function highlightMatch(index) {
        if (!matches.length || index < 0 || index >= matches.length) {
            if (findCount) {
                findCount.textContent = findInput?.value.trim() ? '0 / 0' : '';
                findCount.hidden = !findInput?.value.trim();
            }
            return;
        }
        currentMatchIndex = index;
        const match = matches[index];
        editor.chain().setTextSelection(match).scrollIntoView().run();
        if (findCount) {
            findCount.textContent = `${index + 1} / ${matches.length}`;
            findCount.hidden = false;
        }
    }

    function updateFind(keepIndex = false) {
        const query = findInput?.value ?? '';
        if (!query) {
            matches = [];
            if (findCount) findCount.hidden = true;
            return;
        }
        matches = getMatches(query);
        if (matches.length === 0) {
            if (findCount) {
                findCount.textContent = 'No results';
                findCount.hidden = false;
            }
            return;
        }
        if (!keepIndex) {
            const curPos = editor.state.selection.from;
            const nextIdx = matches.findIndex((m) => m.from >= curPos);
            currentMatchIndex = nextIdx >= 0 ? nextIdx : 0;
        } else {
            currentMatchIndex = Math.min(currentMatchIndex, matches.length - 1);
        }
        highlightMatch(currentMatchIndex);
    }

    function toggleFindBar(open, showReplace = false) {
        if (!findBar) return;
        findBar.hidden = !open;
        toolbar.querySelector('[data-command=find]')?.setAttribute('aria-expanded', String(open));
        if (open) {
            toggleLinkBar(false);
            if (showReplace && replaceGroup) replaceGroup.hidden = false;
            const selectedText = editor.state.doc.textBetween(
                editor.state.selection.from,
                editor.state.selection.to,
                ' '
            ).trim();
            if (selectedText && selectedText.length < 50 && findInput) {
                findInput.value = selectedText;
            }
            updateFind();
            if (showReplace && findInput?.value && replaceInput) {
                replaceInput.focus();
                replaceInput.select();
            } else if (findInput) {
                findInput.focus();
                findInput.select();
            }
        } else {
            editor.commands.focus();
        }
    }

    function replaceCurrent() {
        const query = findInput?.value ?? '';
        if (!query || !matches.length) return;
        const match = matches[currentMatchIndex];
        const replacement = replaceInput?.value ?? '';
        editor.chain().focus().insertContentAt({ from: match.from, to: match.to }, replacement).run();
        matches = getMatches(query);
        if (matches.length > 0) {
            if (currentMatchIndex >= matches.length) currentMatchIndex = 0;
            highlightMatch(currentMatchIndex);
        } else if (findCount) {
            findCount.textContent = 'No results';
        }
    }

    function replaceAllMatches() {
        const query = findInput?.value ?? '';
        if (!query || !matches.length) return;
        const replacement = replaceInput?.value ?? '';
        const { tr } = editor.state;
        for (let i = matches.length - 1; i >= 0; i--) {
            const m = matches[i];
            tr.insertText(replacement, m.from, m.to);
        }
        editor.view.dispatch(tr);
        matches = [];
        if (findCount) findCount.textContent = 'Replaced all';
    }

    findInput?.addEventListener('input', () => updateFind());
    findInput?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            if (!matches.length) return;
            if (e.shiftKey) {
                currentMatchIndex = (currentMatchIndex - 1 + matches.length) % matches.length;
            } else {
                currentMatchIndex = (currentMatchIndex + 1) % matches.length;
            }
            highlightMatch(currentMatchIndex);
        } else if (e.key === 'Escape') {
            e.preventDefault();
            toggleFindBar(false);
        }
    });

    replaceInput?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            replaceCurrent();
        } else if (e.key === 'Escape') {
            e.preventDefault();
            toggleFindBar(false);
        }
    });

    findNext?.addEventListener('click', () => {
        if (!matches.length) return;
        currentMatchIndex = (currentMatchIndex + 1) % matches.length;
        highlightMatch(currentMatchIndex);
    });

    findPrev?.addEventListener('click', () => {
        if (!matches.length) return;
        currentMatchIndex = (currentMatchIndex - 1 + matches.length) % matches.length;
        highlightMatch(currentMatchIndex);
    });

    replaceOneBtn?.addEventListener('click', replaceCurrent);
    replaceAllBtn?.addEventListener('click', replaceAllMatches);
    toggleReplaceBtn?.addEventListener('click', () => {
        if (!replaceGroup) return;
        replaceGroup.hidden = !replaceGroup.hidden;
        if (!replaceGroup.hidden) replaceInput?.focus();
    });
    findCloseBtn?.addEventListener('click', () => toggleFindBar(false));

    // ---------- Document Statistics & Dialogs ----------
    function openStats() {
        if (!statsDialog) return;
        const words = editor.storage.characterCount.words();
        const chars = editor.storage.characterCount.characters();
        const readingMin = words < 100 ? '< 1 min' : `${Math.ceil(words / 200)} min`;
        const speakingMin = words < 60 ? '< 1 min' : `${Math.ceil(words / 130)} min`;
        let headings = 0;
        let paragraphs = 0;
        editor.state.doc.descendants((node) => {
            if (node.type.name === 'heading') headings++;
            if (node.type.name === 'paragraph') paragraphs++;
        });
        const setVal = (sel, val) => {
            const el = statsDialog.querySelector(sel);
            if (el) el.textContent = val;
        };
        const { totalPages } = getPageStats();
        setVal('[data-stat-pages]', totalPages.toLocaleString());
        setVal('[data-stat-words]', words.toLocaleString());
        setVal('[data-stat-chars]', chars.toLocaleString());
        setVal('[data-stat-reading]', readingMin);
        setVal('[data-stat-speaking]', speakingMin);
        setVal('[data-stat-headings]', headings.toLocaleString());
        setVal('[data-stat-paragraphs]', paragraphs.toLocaleString());
        statsDialog.showModal();
    }
    count?.addEventListener('click', openStats);

    for (const dialog of [statsDialog, shortcutsDialog, imageDialog].filter(Boolean)) {
        dialog.addEventListener('click', (event) => {
            if (event.target.closest('[data-dialog-close]') || event.target === dialog) {
                dialog.close();
            }
        });
    }

    // ---------- Image Insertion & Management ----------
    if (imageDialog) {
        const uploadTabBtn = imageDialog.querySelector('[data-image-tab="upload"]');
        const urlTabBtn = imageDialog.querySelector('[data-image-tab="url"]');
        const uploadPanel = imageDialog.querySelector('[data-image-panel="upload"]');
        const urlPanel = imageDialog.querySelector('[data-image-panel="url"]');
        const dropzone = imageDialog.querySelector('[data-image-dropzone]');
        const modalFileInput = imageDialog.querySelector('[data-modal-file-input]');
        const uploadProgress = imageDialog.querySelector('[data-image-upload-progress]');
        const urlInput = imageDialog.querySelector('[data-image-url-input]');
        const captionInput = imageDialog.querySelector('[data-image-caption-input]');
        const altInput = imageDialog.querySelector('[data-image-alt-input]');
        const insertBtn = imageDialog.querySelector('[data-insert-image-btn]');

        let currentTab = 'upload';
        let pendingFile = null;

        function setTab(tab) {
            currentTab = tab;
            if (uploadTabBtn) uploadTabBtn.className = tab === 'upload' ? 'btn btn-sm btn-secondary' : 'btn btn-sm btn-ghost';
            if (urlTabBtn) urlTabBtn.className = tab === 'url' ? 'btn btn-sm btn-secondary' : 'btn btn-sm btn-ghost';
            if (uploadPanel) uploadPanel.hidden = tab !== 'upload';
            if (urlPanel) urlPanel.hidden = tab !== 'url';
        }

        uploadTabBtn?.addEventListener('click', () => setTab('upload'));
        urlTabBtn?.addEventListener('click', () => setTab('url'));

        dropzone?.addEventListener('click', () => modalFileInput?.click());
        dropzone?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                modalFileInput?.click();
            }
        });
        dropzone?.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropzone.classList.add('dragover');
        });
        dropzone?.addEventListener('dragleave', () => dropzone.classList.remove('dragover'));
        dropzone?.addEventListener('drop', (e) => {
            e.preventDefault();
            dropzone.classList.remove('dragover');
            const file = Array.from(e.dataTransfer?.files || []).find((f) => f.type.startsWith('image/'));
            if (file) handleImageFileChosen(file);
        });

        modalFileInput?.addEventListener('change', () => {
            const file = modalFileInput.files?.[0];
            if (file) handleImageFileChosen(file);
        });

        async function handleImageFileChosen(file) {
            pendingFile = file;
            if (uploadProgress) uploadProgress.hidden = false;
            const url = await uploadImageFile(file);
            if (uploadProgress) uploadProgress.hidden = true;
            if (url) {
                insertImage({
                    src: url,
                    title: captionInput?.value.trim() || '',
                    alt: altInput?.value.trim() || file.name.replace(/\.[^.]+$/, ''),
                });
                imageDialog.close();
                if (captionInput) captionInput.value = '';
                if (altInput) altInput.value = '';
                if (modalFileInput) modalFileInput.value = '';
                pendingFile = null;
            }
        }

        insertBtn?.addEventListener('click', async () => {
            if (currentTab === 'url') {
                const src = urlInput?.value.trim();
                if (!src) return;
                insertImage({
                    src,
                    title: captionInput?.value.trim() || '',
                    alt: altInput?.value.trim() || '',
                });
                imageDialog.close();
                if (urlInput) urlInput.value = '';
                if (captionInput) captionInput.value = '';
                if (altInput) altInput.value = '';
            } else if (pendingFile) {
                await handleImageFileChosen(pendingFile);
            } else {
                modalFileInput?.click();
            }
        });
    }

    // ---------- Floating Image Controls & Selection ----------
    let activeFigureEl = null;
    let selectedImagePos = null;

    function positionImageToolbar(figureEl) {
        if (!imageToolbar || !figureEl) return;
        imageToolbar.hidden = false;
        const rect = figureEl.getBoundingClientRect();
        imageToolbar.style.position = 'fixed';
        imageToolbar.style.top = `${Math.max(60, rect.top - 10)}px`;
        imageToolbar.style.left = `${Math.max(120, Math.min(rect.left + (rect.width / 2), window.innerWidth - 130))}px`;

        const currentAlign = figureEl.dataset.align || 'center';
        const currentWidth = figureEl.dataset.width || '100%';
        imageToolbar.querySelectorAll('[data-image-align]').forEach((btn) => {
            btn.setAttribute('aria-pressed', String(btn.dataset.imageAlign === currentAlign));
        });
        imageToolbar.querySelectorAll('[data-image-width]').forEach((btn) => {
            btn.dataset.active = String(btn.dataset.imageWidth === currentWidth);
        });
    }

    function hideImageToolbar() {
        if (imageToolbar) imageToolbar.hidden = true;
        if (activeFigureEl) activeFigureEl.classList.remove('is-selected');
        activeFigureEl = null;
        selectedImagePos = null;
    }

    host.querySelector('[data-note-body]').addEventListener('click', (event) => {
        const figure = event.target.closest('figure.note-image-figure');
        if (figure) {
            try {
                const pos = editor.view.posAtDOM(figure, 0);
                if (typeof pos === 'number' && pos >= 0) {
                    if (activeFigureEl && activeFigureEl !== figure) activeFigureEl.classList.remove('is-selected');
                    activeFigureEl = figure;
                    activeFigureEl.classList.add('is-selected');
                    selectedImagePos = pos;
                    positionImageToolbar(figure);
                    return;
                }
            } catch {}
        }
        if (!event.target.closest('#note-image-toolbar')) {
            hideImageToolbar();
        }
    });

    imageToolbar?.addEventListener('click', (event) => {
        if (!activeFigureEl) return;
        try {
            selectedImagePos = editor.view.posAtDOM(activeFigureEl, 0);
        } catch {}
        if (typeof selectedImagePos !== 'number' || selectedImagePos < 0) return;

        const alignBtn = event.target.closest('[data-image-align]');
        if (alignBtn) {
            const align = alignBtn.dataset.imageAlign;
            editor.chain().setNodeSelection(selectedImagePos).updateAttributes('image', { align }).run();
            activeFigureEl.dataset.align = align;
            positionImageToolbar(activeFigureEl);
            return;
        }

        const widthBtn = event.target.closest('[data-image-width]');
        if (widthBtn) {
            const width = widthBtn.dataset.imageWidth;
            editor.chain().setNodeSelection(selectedImagePos).updateAttributes('image', { width }).run();
            activeFigureEl.dataset.width = width;
            positionImageToolbar(activeFigureEl);
            return;
        }

        const actionBtn = event.target.closest('[data-image-action]');
        if (actionBtn) {
            const action = actionBtn.dataset.imageAction;
            if (action === 'delete') {
                editor.chain().setNodeSelection(selectedImagePos).deleteSelection().run();
                hideImageToolbar();
                return;
            }
            if (action === 'caption') {
                const node = editor.state.doc.nodeAt(selectedImagePos);
                const currentCaption = node?.attrs?.title || '';
                const newCaption = window.prompt('Image caption:', currentCaption);
                if (newCaption !== null) {
                    editor.chain().setNodeSelection(selectedImagePos).updateAttributes('image', { title: newCaption.trim() }).run();
                }
                return;
            }
        }
    });

    // Drag to resize handle
    host.querySelector('[data-note-body]').addEventListener('mousedown', (event) => {
        const handle = event.target.closest('.note-image-resize-handle');
        if (!handle) return;
        event.preventDefault();
        event.stopPropagation();
        const figure = handle.closest('figure.note-image-figure');
        if (!figure) return;
        let pos = null;
        try {
            pos = editor.view.posAtDOM(figure, 0);
        } catch {}

        const startX = event.clientX;
        const containerWidth = figure.parentElement.offsetWidth || 800;
        const initialWidth = figure.offsetWidth;

        function onMouseMove(e) {
            const diffX = e.clientX - startX;
            const newWidthPx = Math.max(120, Math.min(containerWidth, initialWidth + diffX * 2));
            const pct = Math.max(20, Math.min(100, Math.round((newWidthPx / containerWidth) * 100)));
            figure.dataset.width = `${pct}%`;
            figure.style.maxWidth = `${pct}%`;
            positionImageToolbar(figure);
        }

        function onMouseUp() {
            document.removeEventListener('mousemove', onMouseMove);
            document.removeEventListener('mouseup', onMouseUp);
            const finalWidth = figure.dataset.width;
            if (finalWidth && typeof pos === 'number' && pos >= 0) {
                editor.chain().setNodeSelection(pos).updateAttributes('image', { width: finalWidth }).run();
            }
        }

        document.addEventListener('mousemove', onMouseMove);
        document.addEventListener('mouseup', onMouseUp);
    });

    window.addEventListener('resize', hideImageToolbar);
    const noteContentEl = host.querySelector('.note-content');
    noteContentEl?.addEventListener('scroll', () => {
        if (activeFigureEl) positionImageToolbar(activeFigureEl);
        if (!editor.isFocused) {
            const { currentPage, totalPages, words, chars } = getPageStats();
            count.textContent = `Page ${currentPage} of ${totalPages} · ${words} ${words === 1 ? 'word' : 'words'} · ${chars.toLocaleString()} characters`;
        }
    }, { passive: true });
    noteContentEl?.addEventListener('click', (event) => {
        if (event.target === event.currentTarget) {
            editor.commands.focus('end');
        }
    });

    host.querySelector('[data-note-body]').addEventListener('keydown', (event) => {
        const isMod = event.ctrlKey || event.metaKey;
        const key = event.key.toLowerCase();

        // Ctrl+Enter (Insert Page Break)
        if (isMod && !event.shiftKey && !event.altKey && event.key === 'Enter') {
            event.preventDefault();
            COMMANDS.pageBreak[0](editor.chain().focus()).run();
            updateToolbar();
            return;
        }

        if (event.key === 'Tab' && !editor.isActive('table')) {
            event.preventDefault();
            if (event.shiftKey) {
                COMMANDS.outdent[0](editor.chain().focus()).run();
            } else {
                COMMANDS.indent[0](editor.chain().focus()).run();
            }
            return;
        }

        // Ctrl+Z (Undo)
        if (isMod && !event.shiftKey && !event.altKey && key === 'z') {
            event.preventDefault();
            editor.chain().focus().undo().run();
            updateToolbar();
            return;
        }

        // Ctrl+Y or Ctrl+Shift+Z (Redo)
        if ((isMod && !event.shiftKey && !event.altKey && key === 'y') ||
            (isMod && event.shiftKey && !event.altKey && key === 'z')) {
            event.preventDefault();
            editor.chain().focus().redo().run();
            updateToolbar();
            return;
        }

        // Ctrl+A (Select All)
        if (isMod && !event.shiftKey && !event.altKey && key === 'a') {
            event.preventDefault();
            editor.chain().focus().selectAll().run();
            return;
        }

        // Ctrl+S (Save)
        if (isMod && !event.shiftKey && !event.altKey && key === 's') {
            event.preventDefault();
            saveNow();
            return;
        }

        // Text Alignments (Ctrl+L, Ctrl+E, Ctrl+R, Ctrl+J)
        if (isMod && !event.shiftKey && !event.altKey) {
            if (key === 'l') {
                event.preventDefault();
                editor.chain().focus().setTextAlign('left').run();
                updateToolbar();
                return;
            }
            if (key === 'e') {
                event.preventDefault();
                editor.chain().focus().setTextAlign('center').run();
                updateToolbar();
                return;
            }
            if (key === 'r') {
                event.preventDefault();
                editor.chain().focus().setTextAlign('right').run();
                updateToolbar();
                return;
            }
            if (key === 'j') {
                event.preventDefault();
                editor.chain().focus().setTextAlign('justify').run();
                updateToolbar();
                return;
            }
            if (key === 'k') {
                event.preventDefault();
                toggleLinkBar(true);
                return;
            }
            if (key === '\\' || (event.code === 'Space' && event.shiftKey)) {
                // Clear formatting: Ctrl+\ or Ctrl+Shift+Space
                event.preventDefault();
                isolateSelection(editor);
                editor.chain().focus().unsetAllMarks().clearNodes().run();
                updateToolbar();
                return;
            }
            if (event.key === '=' || event.key === '+') {
                // Subscript: Ctrl+=
                event.preventDefault();
                editor.chain().focus().toggleSubscript().run();
                updateToolbar();
                return;
            }
        }

        // Formatting & Lists with Shift
        if (isMod && event.shiftKey && !event.altKey) {
            if (key === 'x') {
                // Strikethrough: Ctrl+Shift+X
                event.preventDefault();
                editor.chain().focus().toggleStrike().run();
                updateToolbar();
                return;
            }
            if (event.key === '+' || event.key === '=' || event.code === 'Equal') {
                // Superscript: Ctrl+Shift++
                event.preventDefault();
                editor.chain().focus().toggleSuperscript().run();
                updateToolbar();
                return;
            }
            if (key === 'i') {
                // Insert Image: Ctrl+Shift+I
                event.preventDefault();
                imageDialog?.showModal();
                return;
            }
            if (key === '8' || key === '*' || key === 'l') {
                // Bullet List: Ctrl+Shift+8 or Ctrl+Shift+L
                event.preventDefault();
                isolateSelection(editor);
                editor.chain().focus().toggleBulletList().run();
                updateToolbar();
                return;
            }
            if (key === '7' || key === '&') {
                // Numbered List: Ctrl+Shift+7
                event.preventDefault();
                isolateSelection(editor);
                editor.chain().focus().toggleOrderedList().run();
                updateToolbar();
                return;
            }
            if (key === '9' || key === '(') {
                // Task List: Ctrl+Shift+9
                event.preventDefault();
                isolateSelection(editor);
                editor.chain().focus().toggleTaskList().run();
                updateToolbar();
                return;
            }
        }

        // Headings & Normal text: Ctrl+Alt+0..4
        if (isMod && event.altKey && !event.shiftKey) {
            if (key === '0') {
                event.preventDefault();
                isolateSelection(editor);
                editor.chain().focus().setParagraph().run();
                updateToolbar();
                return;
            }
            if (key === '1' || key === '2' || key === '3' || key === '4') {
                event.preventDefault();
                isolateSelection(editor);
                editor.chain().focus().setHeading({ level: Number(key) }).run();
                updateToolbar();
                return;
            }
        }
    });

    document.addEventListener('keydown', (event) => {
        if (document.querySelector('dialog[open]')) return;
        const isMod = event.ctrlKey || event.metaKey;
        const key = event.key.toLowerCase();
        const activeTag = document.activeElement?.tagName;
        const inInput = ['INPUT', 'TEXTAREA'].includes(activeTag) || document.activeElement?.isContentEditable;

        if (isMod && !event.shiftKey && !event.altKey && key === 's') {
            event.preventDefault();
            saveNow();
            return;
        }
        if (isMod && !event.shiftKey && !event.altKey && key === 'f') {
            event.preventDefault();
            toggleFindBar(true, false);
            return;
        }
        if (isMod && !event.shiftKey && !event.altKey && key === 'h') {
            event.preventDefault();
            toggleFindBar(true, true);
            return;
        }
        if ((isMod && event.key === '/') || event.key === 'F1') {
            event.preventDefault();
            shortcutsDialog?.showModal();
            return;
        }
        if (isMod && event.shiftKey && !event.altKey && key === 'i') {
            event.preventDefault();
            imageDialog?.showModal();
            return;
        }

        // When focus is outside inputs and editor (e.g. user clicked toolbar or page margins)
        if (!inInput) {
            if (isMod && !event.shiftKey && !event.altKey && key === 'z') {
                event.preventDefault();
                editor.chain().focus().undo().run();
                updateToolbar();
                return;
            }
            if ((isMod && !event.shiftKey && !event.altKey && key === 'y') ||
                (isMod && event.shiftKey && !event.altKey && key === 'z')) {
                event.preventDefault();
                editor.chain().focus().redo().run();
                updateToolbar();
                return;
            }
            if (isMod && !event.shiftKey && !event.altKey && key === 'a') {
                event.preventDefault();
                editor.chain().focus().selectAll().run();
                return;
            }
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
    const focusButtons = page.querySelectorAll('[data-note-focus]');
    function setReading(on) {
        page.toggleAttribute('data-reading', on);
        if (on) {
            toggleLinkBar(false);
            toggleFindBar(false);
            hideImageToolbar();
        }
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
        focusButtons[0]?.focus();
    }
    page.querySelector('[data-note-read]').addEventListener('click', () => setReading(!page.hasAttribute('data-reading')));
    focusButtons.forEach((btn) => btn.addEventListener('click', () => setFocus(!page.hasAttribute('data-focus'))));

    // ---------- Document View Mode: Pages (A4) vs Continuous ----------
    const viewToggleButtons = page.querySelectorAll('[data-note-view-toggle]');
    function setPageViewMode(mode) {
        const activeMode = mode === 'continuous' ? 'continuous' : 'pages';
        page.setAttribute('data-page-view', activeMode);
        try {
            localStorage.setItem('vistud.note-view-mode', activeMode);
        } catch {}
        viewToggleButtons.forEach((btn) => {
            btn.title = activeMode === 'pages' ? 'Switch to Full Width view' : 'Switch to Pages (A4) view';
        });
        updateToolbar();
    }
    const savedViewMode = localStorage.getItem('vistud.note-view-mode') || 'pages';
    setPageViewMode(savedViewMode);
    viewToggleButtons.forEach((btn) => {
        btn.addEventListener('click', () => {
            const currentMode = page.getAttribute('data-page-view') || 'pages';
            setPageViewMode(currentMode === 'pages' ? 'continuous' : 'pages');
        });
    });

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
