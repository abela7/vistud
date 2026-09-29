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

import { Editor, Extension, Node, mergeAttributes } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Document from '@tiptap/extension-document';
import { TaskItem, TaskList } from '@tiptap/extension-list';
import { CharacterCount, Placeholder } from '@tiptap/extensions';
import { UniqueID } from '@tiptap/extension-unique-id';
import TextAlign from '@tiptap/extension-text-align';
import Highlight from '@tiptap/extension-highlight';
import Subscript from '@tiptap/extension-subscript';
import Superscript from '@tiptap/extension-superscript';
import { TableKit } from '@tiptap/extension-table';
import { Markdown } from '@tiptap/markdown';
import { Mathematics } from '@tiptap/extension-mathematics';
import katex from 'katex';
import 'katex/dist/katex.min.css';
import { NodeSelection, TextSelection, Plugin, PluginKey } from '@tiptap/pm/state';
import { Decoration, DecorationSet } from '@tiptap/pm/view';
import { canSplit } from '@tiptap/pm/transform';
import { deleteDrafts, openDrafts } from './drafts.js';
import { createAutosave, randomId, xsrf } from './autosave.js';
import { toast } from '../toasts.js';
import { openNoteWindow, show as showInWindow } from '../note-window.js';

const BLOCKS = ['paragraph', 'heading', 'blockquote', 'bulletList', 'orderedList', 'taskList', 'codeBlock', 'horizontalRule', 'listItem', 'taskItem', 'table', 'tableRow', 'tableHeader', 'tableCell', 'callout', 'image', 'pageBreak', 'blockMath'];

/** A Markdown alert (> [!THEOREM]) as one of the callout's tones; anything else is a note. */
const CALLOUT_TONES = { theorem: 'theorem', law: 'theorem', definition: 'definition', formula: 'formula', equation: 'formula', example: 'example' };

/** A picture's address a note can keep (app/Study/NoteDoc.php): the web, or one uploaded for a note. */
const KEEPABLE_PICTURE = /^(https?:\/\/|\/notes\/images\/)/i;

/** What a formula's LaTeX is drawn with: never trusted with anything but drawing, and a mistake shows as the words. */
const KATEX = { throwOnError: false, strict: 'ignore', trust: false, maxSize: 50, errorColor: 'currentColor' };

/**
 * The note itself: it keeps how it is shown (A4 pages or full width) with its words, so it opens the same way
 * everywhere (app/Study/NoteDoc.php). Unset, the student's last choice on this device applies.
 */
const NoteDocument = Document.extend({
    addAttributes() {
        return { view: { default: null } };
    },
});

/** An editor document without the empty attributes the server drops anyway: a big note's saves stay small. */
function compact(node) {
    const out = { type: node.type };
    if (node.attrs) {
        const attrs = Object.fromEntries(Object.entries(node.attrs).filter(([, value]) => value !== null && value !== undefined));
        if (Object.keys(attrs).length) out.attrs = attrs;
    }
    if (node.text !== undefined) out.text = node.text;
    if (node.marks?.length) out.marks = node.marks.map(compact);
    if (node.content?.length) out.content = node.content.map(compact);
    return out;
}

/**
 * Blocks brought in all at once (an imported file, pasted Markdown) get their IDs here. Left to UniqueID, each
 * block would be a step of its own, and a long file would take seconds to come in.
 */
function withIds(nodes) {
    return nodes.map((node) => {
        const out = { ...node };
        if (BLOCKS.includes(node.type) && !node.attrs?.id) out.attrs = { ...node.attrs, id: randomId(8) };
        if (node.content) out.content = withIds(node.content);
        return out;
    });
}

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

/** A STEM callout card (Theorem, Definition, Key Formula, Example, Note). In Markdown it is an alert, > [!THEOREM] and so on. */
const NoteCallout = Node.create({
    name: 'callout',
    group: 'block',
    content: 'block+',
    defining: true,
    // Ahead of the quote, which takes the quotes that aren't alerts.
    priority: 110,
    markdownTokenName: 'blockquote',
    parseMarkdown(token, helpers) {
        const first = token.tokens?.[0];
        const alert = first?.type === 'paragraph' ? /^\[!([A-Za-z]+)\][ \t]*(?:\r?\n|$)/.exec(first.text ?? '') : null;
        if (!alert) return null;
        const tone = CALLOUT_TONES[alert[1].toLowerCase()] ?? 'note';
        const opening = (first.text ?? '').slice(alert[0].length).trim();
        const content = [
            ...(opening ? [helpers.createNode('paragraph', undefined, helpers.parseInline(helpers.tokenizeInline(opening)))] : []),
            ...helpers.parseBlockChildren(token.tokens.slice(1)),
        ];
        return helpers.createNode('callout', { tone }, content.length ? content : [helpers.createNode('paragraph')]);
    },
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
    // In Markdown, a picture on a line of its own is a block; one inside a sentence can only be words here.
    markdownTokenizer: {
        name: 'image',
        level: 'block',
        start: (src) => src.indexOf('!['),
        tokenize: (src) => {
            const m = /^!\[([^\]]*)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)[ \t]*(?:\n+|$)/.exec(src);
            return m ? { type: 'image', raw: m[0], href: m[2], text: m[1], title: m[3] ?? '', block: true } : undefined;
        },
    },
    parseMarkdown(token, helpers) {
        const src = token.href ?? '';
        const keepable = KEEPABLE_PICTURE.test(src);
        if (token.block && keepable) return { type: 'image', attrs: { src, alt: token.text ?? '', title: token.title ?? '' } };
        const words = token.text || token.title || 'picture';
        return keepable ? helpers.createTextNode(words, [{ type: 'link', attrs: { href: src } }]) : helpers.createTextNode(`[${words}]`);
    },

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

/**
 * After a block that holds no text (a page break, a picture), Tiptap leaves it
 * selected, and the next key would replace it. The cursor goes on in the text
 * after it instead, in a new paragraph when there's none.
 */
function cursorPast(tr) {
    if (!(tr.selection instanceof NodeSelection)) return;
    const { $to } = tr.selection;
    if ($to.nodeAfter?.isTextblock) {
        tr.setSelection(TextSelection.create(tr.doc, $to.pos + 1));
    } else {
        const at = $to.nodeAfter ? $to.pos : $to.end();
        tr.insert(at, tr.doc.type.schema.nodes.paragraph.create());
        tr.setSelection(TextSelection.create(tr.doc, at + 1));
    }
    tr.scrollIntoView();
}

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
            // As Word does: the writing goes on on the new page.
            setPageBreak: () => ({ chain, state }) => {
                const next = chain();
                if (state.selection instanceof NodeSelection) next.insertContentAt(state.selection.to, { type: this.name });
                else next.insertContent({ type: this.name });
                return next.command(({ tr, dispatch }) => {
                    if (dispatch) cursorPast(tr);
                    return true;
                }).run();
            },
        };
    },
});

function createAutoPageBreakWidget(pageNumber) {
    const el = document.createElement('div');
    el.className = 'note-page-break note-auto-page-break';
    el.setAttribute('data-auto-page-break', '');
    el.setAttribute('data-page-number', String(pageNumber));
    el.contentEditable = 'false';

    const lineLeft = document.createElement('span');
    lineLeft.className = 'page-break-line';
    lineLeft.setAttribute('aria-hidden', 'true');

    const badge = document.createElement('span');
    badge.className = 'page-break-badge';
    badge.textContent = `Page ${pageNumber}`;

    const lineRight = document.createElement('span');
    lineRight.className = 'page-break-line';
    lineRight.setAttribute('aria-hidden', 'true');

    el.appendChild(lineLeft);
    el.appendChild(badge);
    el.appendChild(lineRight);
    return el;
}

const paginationPluginKey = new PluginKey('autoPagination');

const NoteAutoPagination = Extension.create({
    name: 'autoPagination',
    addProseMirrorPlugins() {
        return [
            new Plugin({
                key: paginationPluginKey,
                state: {
                    init() {
                        return DecorationSet.empty;
                    },
                    apply(tr, set) {
                        set = set.map(tr.mapping, tr.doc);
                        const meta = tr.getMeta(paginationPluginKey);
                        if (meta !== undefined) {
                            return meta;
                        }
                        return set;
                    },
                },
                props: {
                    decorations(state) {
                        return this.getState(state);
                    },
                },
            }),
        ];
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
    pageBreak: [(c) => c.setPageBreak(), null],
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
 * Pasted plain text that is written as Markdown: a heading, a list, a fence, a table, a quote, or bold or a
 * formula in it. A formula is $…$ with no space just inside the dollars and no digit after the last (Pandoc's
 * rule), so "$5 and $10" is money, not a formula.
 */
function looksLikeMarkdown(text) {
    return /^(#{1,6} |[-*+] |\d+\. |> |```|\|.*\|)/m.test(text) || /\*\*[^*\n]+\*\*|(?<![$\w])\$(?=\S)[^$\n]+?(?<=\S)\$(?![$\d])|\$\$[^$]+\$\$/.test(text);
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
    // Only the note, in a window of its own beside the study material (resources/js/note-window.js).
    const inWindow = host.hasAttribute('data-window');
    // Set up further down; the editor's callbacks wait for them.
    let viewReady = false;
    let shownView = null;
    let importReady = false;
    let countTimer = null;
    const clientId = tabId();
    let loading = true;
    let editor = null;
    let autosave = null;
    // Listeners on the window last as long as this page: a page swapped in without reloading ends them (resources/js/page.js).
    const leaving = new AbortController();
    const on = (target, type, listener) => target.addEventListener(type, listener, { signal: leaving.signal });
    let left = false;

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
    // Left for another page while the drafts were opening: nothing to set up.
    if (!host.isConnected) return null;

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
            const data = await res.json().catch(() => null);
            if (res.ok && data?.url) return data.url;
            toast(Object.values(data?.error?.details?.fields ?? {})[0]?.[0] ?? 'The picture couldn\'t be added. Try again.', 'info');
        } catch {
            toast('The picture couldn\'t be added. Check your connection and try again.', 'info');
        }
        return null;
    }

    function insertImage({ src, alt = '', title = '', width = '100%', align = 'center' }) {
        if (!src) return;
        editor.chain().focus().insertContent({
            type: 'image',
            attrs: { src, alt, title, width, align },
        }).command(({ tr }) => {
            cursorPast(tr);
            return true;
        }).run();
    }

    /**
     * Word's shortcuts, ahead of Tiptap's own (Ctrl+E centres, where Tiptap
     * would make code). A block style takes only the selected words, not the
     * whole paragraph. Tab stays Tiptap's: it indents in a list or a table,
     * and elsewhere it leaves the note, as a keyboard user expects.
     */
    const NoteKeys = Extension.create({
        name: 'noteKeys',
        priority: 1000,
        addKeyboardShortcuts() {
            const run = (make, isolate = false) => () => {
                if (isolate) isolateSelection(this.editor);
                make(this.editor.chain().focus()).run();
                return true;
            };
            const keys = {
                'Mod-Enter': run((c) => c.setPageBreak()),
                'Mod-l': run((c) => c.setTextAlign('left')),
                'Mod-e': run((c) => c.setTextAlign('center')),
                'Mod-r': run((c) => c.setTextAlign('right')),
                'Mod-j': run((c) => c.setTextAlign('justify')),
                'Mod-Shift-x': run((c) => c.toggleStrike()),
                'Mod-=': run((c) => c.toggleSubscript()),
                'Mod-Shift-=': run((c) => c.toggleSuperscript()),
                'Mod-\\': run((c) => c.unsetAllMarks().clearNodes(), true),
                'Mod-Shift-Space': run((c) => c.unsetAllMarks().clearNodes(), true),
                'Mod-Alt-0': run((c) => c.setParagraph(), true),
                'Mod-Shift-8': run((c) => c.toggleBulletList(), true),
                'Mod-Shift-l': run((c) => c.toggleBulletList(), true),
                'Mod-Shift-7': run((c) => c.toggleOrderedList(), true),
                'Mod-Shift-9': run((c) => c.toggleTaskList(), true),
                'Mod-k': () => {
                    toggleLinkBar(true);
                    return true;
                },
                'Mod-Shift-i': () => {
                    imageDialog?.showModal();
                    return true;
                },
            };
            for (const level of [1, 2, 3, 4]) keys[`Mod-Alt-${level}`] = run((c) => c.setHeading({ level }), true);
            return keys;
        },
    });

    editor = new Editor({
        element: host.querySelector('[data-note-body]'),
        // Its default styles hardcode colours; resources/css/editor.css has themed ones.
        injectCSS: false,
        content: note.doc,
        extensions: [
            NoteDocument,
            StarterKit.configure({
                document: false,
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
            NoteAutoPagination,
            NoteKeys,
            Subscript,
            Superscript,
            TableKit.configure({ table: { resizable: false } }),
            Markdown,
            Mathematics.configure({
                katexOptions: KATEX,
                inlineOptions: { onClick: (node, pos) => openFormula('inline', node, pos) },
                blockOptions: { onClick: (node, pos) => openFormula('block', node, pos) },
            }),
            CharacterCount,
            Placeholder.configure({ placeholder: 'Start writing…' }),
            // An import replaces the whole note with blocks that have their IDs already (withIds): nothing to check.
            UniqueID.configure({ types: BLOCKS, generateID: () => randomId(8), filterTransaction: (tr) => !tr.getMeta('idsGiven') }),
        ],
        editorProps: {
            attributes: { class: 'note-prose', 'aria-label': 'Note', 'aria-multiline': 'true', role: 'textbox' },
            handlePaste: (view, event) => {
                const plain = event.clipboardData?.getData('text/plain') ?? '';
                if (plain && !event.clipboardData.getData('text/html') && !editor.isActive('codeBlock') && looksLikeMarkdown(plain)) {
                    event.preventDefault();
                    const doc = editor.markdown.parse(plain);
                    editor.chain().focus().insertContent({ ...doc, content: withIds(doc.content ?? []) }).run();
                    return true;
                }
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
        onUpdate: () => {
            if (!loading) changed();
            showImport();
        },
        onTransaction: ({ transaction: tr }) => {
            if (updatingPagination) return;
            updateToolbar();
            // The note's own layout, when a change brings one (an import, Undo, another tab's version).
            if (viewReady && editor.state.doc.attrs.view !== shownView) setPageViewMode(editor.state.doc.attrs.view);
            if (tr && !tr.docChanged && tr.selectionSet && count) {
                const { currentPage, totalPages, words, chars } = getPageStats();
                count.textContent = `Page ${currentPage} of ${totalPages} · ${words} ${words === 1 ? 'word' : 'words'} · ${chars.toLocaleString()} characters`;
            }
        },
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
            snapshot: () => ({ title: titleField.value, doc: compact(editor.getJSON()) }),
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
        const sent = { title: titleField.value, doc: compact(editor.getJSON()) };
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
        // The page was left meanwhile: the note is made (or not), and this page has nothing more to do.
        if (left) return;
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
            // A new note in its own window stays there: the address keeps ?window=1 for a reload.
            history.replaceState(history.state, '', inWindow ? `${data.url}?window=1` : data.url);
            startAutosave();
            // Whatever was typed while it was being made is saved next.
            if (JSON.stringify(sent) !== JSON.stringify({ title: titleField.value, doc: compact(editor.getJSON()) })) autosave.changed();
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
    on(window, 'resize', growTitle);

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
    const formulaDialog = host.querySelector('[data-formula-dialog]');
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

    // ---------- Zoom controls (MS Word-like) ----------
    let currentZoom = 1.0;
    try {
        const savedZoom = parseFloat(localStorage.getItem('vistud.note-zoom'));
        if (!isNaN(savedZoom) && savedZoom >= 0.5 && savedZoom <= 2.0) {
            currentZoom = Math.round(savedZoom * 100) / 100;
        }
    } catch {}

    const zoomLabel = host.querySelector('[data-zoom-label]');
    const zoomSlider = host.querySelector('[data-zoom-slider]');
    const sheetEl = host.querySelector('[data-note-sheet]');
    let zoomAnimId = null;

    function setZoomImmediate(zoom, persist = true) {
        currentZoom = Math.min(2.0, Math.max(0.5, Math.round(zoom * 100) / 100));
        if (sheetEl) {
            sheetEl.style.setProperty('--note-zoom', String(currentZoom));
            sheetEl.style.zoom = String(currentZoom);
        }
        if (zoomLabel) {
            zoomLabel.textContent = `${Math.round(currentZoom * 100)}%`;
        }
        if (zoomSlider && document.activeElement !== zoomSlider) {
            zoomSlider.value = String(Math.round(currentZoom * 100));
        }
        if (persist) {
            try {
                localStorage.setItem('vistud.note-zoom', String(currentZoom));
            } catch {}
        }
    }

    function animateZoomTo(targetZoom, duration = 160) {
        targetZoom = Math.min(2.0, Math.max(0.5, Math.round(targetZoom * 100) / 100));
        if (Math.abs(targetZoom - currentZoom) < 0.005) {
            setZoomImmediate(targetZoom);
            scheduleCount();
            return;
        }

        if (zoomAnimId) cancelAnimationFrame(zoomAnimId);

        const startZoom = currentZoom;
        const startTime = performance.now();

        function step(now) {
            const elapsed = now - startTime;
            const progress = Math.min(1, elapsed / duration);
            const ease = 1 - Math.pow(1 - progress, 3);
            const val = startZoom + (targetZoom - startZoom) * ease;
            setZoomImmediate(val, false);

            if (progress < 1) {
                zoomAnimId = requestAnimationFrame(step);
            } else {
                zoomAnimId = null;
                setZoomImmediate(targetZoom, true);
                scheduleCount();
            }
        }

        zoomAnimId = requestAnimationFrame(step);
    }

    const zoomIn = () => animateZoomTo(currentZoom + 0.1);
    const zoomOut = () => animateZoomTo(currentZoom - 0.1);
    const resetZoom = () => animateZoomTo(1.0);

    host.querySelector('[data-zoom-in]')?.addEventListener('click', zoomIn);
    host.querySelector('[data-zoom-out]')?.addEventListener('click', zoomOut);
    host.querySelector('[data-zoom-reset]')?.addEventListener('click', resetZoom);

    zoomSlider?.addEventListener('input', (event) => {
        if (zoomAnimId) cancelAnimationFrame(zoomAnimId);
        const val = parseFloat(event.target.value) / 100;
        setZoomImmediate(val, false);
        scheduleCount();
    });

    zoomSlider?.addEventListener('change', (event) => {
        const val = parseFloat(event.target.value) / 100;
        setZoomImmediate(val, true);
        scheduleCount();
    });

    // Apply saved zoom to the sheet and slider
    setZoomImmediate(currentZoom, false);
    if (zoomSlider) zoomSlider.value = String(Math.round(currentZoom * 100));

    // ---------- Automatic Pagination (MS Word A4 standard) & Statistics ----------
    let updatingPagination = false;
    let lastBreakPositions = [];
    let lastTotalPages = 1;

    function getPageStats() {
        const cursorPos = editor ? editor.state.selection.from : 0;
        const words = editor?.storage?.characterCount ? editor.storage.characterCount.words() : 0;
        const chars = editor?.storage?.characterCount ? editor.storage.characterCount.characters() : 0;
        const totalPages = Math.max(1, lastTotalPages);
        const currentPage = Math.min(totalPages, Math.max(1, lastBreakPositions.filter((p) => cursorPos >= p).length + 1));
        return { currentPage, totalPages, words, chars };
    }

    function updatePaginationAndStats() {
        if (left || !editor || !editor.view || !editor.view.dom) return;

        const pageMode = page ? (page.getAttribute('data-page-view') ?? 'pages') : 'pages';
        const isPagesView = pageMode === 'pages';

        const words = editor.storage.characterCount.words();
        const chars = editor.storage.characterCount.characters();
        const cursorPos = editor.state.selection.from;

        if (!isPagesView) {
            const currentDecos = paginationPluginKey.getState(editor.state);
            if (currentDecos && currentDecos.find().length > 0) {
                updatingPagination = true;
                try {
                    editor.view.dispatch(
                        editor.state.tr
                            .setMeta(paginationPluginKey, DecorationSet.empty)
                            .setMeta('addToHistory', false)
                    );
                } finally {
                    updatingPagination = false;
                }
            }

            if (sheetEl) sheetEl.style.setProperty('--note-page-count', '1');

            let manualBreaks = [];
            editor.state.doc.descendants((node, pos) => {
                if (node.type.name === 'pageBreak') manualBreaks.push(pos);
            });

            let totalPages = 1;
            let currentPage = 1;
            if (manualBreaks.length > 0) {
                totalPages = manualBreaks.length + 1;
                currentPage = manualBreaks.filter((p) => cursorPos > p).length + 1;
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

            lastTotalPages = totalPages;
            lastBreakPositions = manualBreaks;
            if (count) count.textContent = `Page ${currentPage} of ${totalPages} · ${words} ${words === 1 ? 'word' : 'words'} · ${chars.toLocaleString()} characters`;
            return;
        }

        // Pages (A4) View:
        const proseEl = editor.view.dom;
        if (!sheetEl || !proseEl) return;

        // A4 sheet height in CSS pixels: 297mm * (96 / 25.4) = ~1122.52px
        const A4_HEIGHT_PX = 1122.52;
        const sheetStyle = window.getComputedStyle(sheetEl);
        const padTop = parseFloat(sheetStyle.paddingTop) || 75.6;
        const padBottom = parseFloat(sheetStyle.paddingBottom) || 75.6;

        const titleH = (titleField ? titleField.offsetHeight : 0) || 45;
        const titleStyle = titleField ? window.getComputedStyle(titleField) : null;
        const titleMb = titleStyle ? (parseFloat(titleStyle.marginBottom) || 0) : 0;
        const headerHeight = titleH + titleMb;

        const page1Budget = Math.max(200, A4_HEIGHT_PX - padTop - padBottom - headerHeight);
        const subsequentBudget = Math.max(200, A4_HEIGHT_PX - padTop - padBottom);

        const doc = editor.state.doc;
        let accumulatedHeight = 0;
        let currentPageNum = 1;
        let currentBudget = page1Budget;
        const breakPositions = [];
        const decorations = [];

        const zoomScale = currentZoom > 0 ? currentZoom : 1;

        doc.forEach((node, pos) => {
            if (node.type.name === 'pageBreak') {
                breakPositions.push(pos);
                currentPageNum++;
                accumulatedHeight = 0;
                currentBudget = subsequentBudget;
                return;
            }

            let dom = editor.view.nodeDOM(pos);
            if (!dom || dom.nodeType !== 1) {
                const domAt = editor.view.domAtPos(pos + 1);
                dom = domAt?.node;
                if (dom && dom.nodeType !== 1) dom = dom.parentElement;
            }

            let blockHeight = 28;
            if (dom && typeof dom.getBoundingClientRect === 'function') {
                const rect = dom.getBoundingClientRect();
                const style = window.getComputedStyle(dom);
                const mt = parseFloat(style.marginTop) || 0;
                const mb = parseFloat(style.marginBottom) || 0;
                blockHeight = (rect.height / zoomScale) + Math.max(mt, mb);
            }

            if (accumulatedHeight > 0 && (accumulatedHeight + blockHeight > currentBudget)) {
                currentPageNum++;
                currentBudget = subsequentBudget;
                accumulatedHeight = blockHeight;
                breakPositions.push(pos);
                const thisPage = currentPageNum;
                decorations.push(
                    Decoration.widget(pos, () => createAutoPageBreakWidget(thisPage), {
                        side: -1,
                        key: `auto-page-${thisPage}`,
                    })
                );
            } else {
                accumulatedHeight += blockHeight;
            }
        });

        const totalPages = currentPageNum;
        lastTotalPages = totalPages;
        lastBreakPositions = breakPositions;

        sheetEl.style.setProperty('--note-page-count', String(totalPages));

        const newBreakKey = breakPositions.join(',');
        const currentDecos = paginationPluginKey.getState(editor.state);
        const oldPositions = currentDecos ? currentDecos.find().map((d) => d.from).sort((a, b) => a - b).join(',') : '';

        if (newBreakKey !== oldPositions) {
            const decos = DecorationSet.create(doc, decorations);
            updatingPagination = true;
            try {
                editor.view.dispatch(
                    editor.state.tr
                        .setMeta(paginationPluginKey, decos)
                        .setMeta('addToHistory', false)
                );
            } finally {
                updatingPagination = false;
            }
        }

        const currentPage = Math.min(totalPages, Math.max(1, breakPositions.filter((p) => cursorPos >= p).length + 1));
        if (count) count.textContent = `Page ${currentPage} of ${totalPages} · ${words} ${words === 1 ? 'word' : 'words'} · ${chars.toLocaleString()} characters`;
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
        // The toolbar's one tab stop is never on a tool that can't take the focus (Undo, before there's anything to undo).
        const stop = items.find((b) => b.tabIndex === 0);
        if (!stop || stop.disabled) {
            const first = items.find((b) => !b.disabled);
            items.forEach((b) => { b.tabIndex = b === first ? 0 : -1; });
        }
        tableBar.hidden = !editor.isActive('table') || page.hasAttribute('data-reading');
        scheduleCount();
    }
    /**
     * The status line counts every word and measures the note, so it waits for a pause in the typing: done on
     * each key, a long note would lag.
     */
    function scheduleCount() {
        clearTimeout(countTimer);
        countTimer = setTimeout(() => {
            if (left) return;
            updatePaginationAndStats();
        }, 250);
    }
    // A click on a tool leaves the focus (and the selection) in the note; the keyboard still reaches the toolbar.
    toolbar.addEventListener('mousedown', (event) => {
        if (event.target.closest('button')) event.preventDefault();
    });
    /** Back to writing at once: Tiptap's own focus waits a frame, and a key pressed meanwhile would go elsewhere. */
    const backToNote = () => editor.view.focus();
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
        backToNote();
        isolateSelection(editor);
        const chain = editor.chain().focus();
        (blockStyle.value === 'paragraph' ? chain.setParagraph() : chain.setHeading({ level: Number(blockStyle.value) })).run();
        updateToolbar();
    });
    toolbar.addEventListener('keydown', (event) => {
        const enabled = items.filter((b) => !b.disabled && b.checkVisibility());
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
        backToNote();
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
        backToNote();
        editor.chain().focus().setTextAlign(item.dataset.align).run();
    });
    if (calloutMenu) {
        calloutMenu.addEventListener('click', (event) => {
            const item = event.target.closest('[data-callout-tone]');
            if (!item) return;
            calloutMenu.hidePopover();
            backToNote();
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
            backToNote();
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
        toggleLinkBar(false);
        backToNote();
        const chain = editor.chain().focus().extendMarkRange('link');
        (href === '' ? chain.unsetLink() : chain.setLink({ href })).run();
    }
    linkBar.querySelector('[data-link-apply]').addEventListener('click', applyLink);
    linkBar.querySelector('[data-link-remove]').addEventListener('click', () => {
        toggleLinkBar(false);
        backToNote();
        editor.chain().focus().extendMarkRange('link').unsetLink().run();
    });
    linkInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            applyLink();
        } else if (event.key === 'Escape') {
            event.preventDefault();
            toggleLinkBar(false);
            backToNote();
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

    for (const dialog of [statsDialog, shortcutsDialog, imageDialog, formulaDialog].filter(Boolean)) {
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
            for (const [button, name] of [[uploadTabBtn, 'upload'], [urlTabBtn, 'url']]) {
                if (!button) continue;
                button.className = tab === name ? 'btn btn-sm btn-secondary' : 'btn btn-sm btn-ghost';
                button.setAttribute('aria-pressed', String(tab === name));
            }
            if (uploadPanel) uploadPanel.hidden = tab !== 'upload';
            if (urlPanel) urlPanel.hidden = tab !== 'url';
        }

        uploadTabBtn?.addEventListener('click', () => setTab('upload'));
        urlTabBtn?.addEventListener('click', () => setTab('url'));

        // The whole drop area opens the file chooser; its button is how the keyboard gets there.
        dropzone?.addEventListener('click', () => modalFileInput?.click());
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
                if (!/^https?:\/\/\S+$/i.test(src)) {
                    toast('Use a web address that starts with https://', 'info');
                    urlInput.focus();
                    return;
                }
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

    // ---------- Importing a Markdown or text file ----------
    /** Plain text as a document: a paragraph per blank line, a line break per line. */
    const textDoc = (text) => ({
        type: 'doc',
        content: text.replace(/\r\n?/g, '\n').split(/\n{2,}/).map((para) => {
            const content = para.split('\n').flatMap((line, i) => [...(i ? [{ type: 'hardBreak' }] : []), ...(line ? [{ type: 'text', text: line }] : [])]);
            return content.length ? { type: 'paragraph', content } : { type: 'paragraph' };
        }),
    });
    const wordsOf = (node) => (node.content ?? []).map((child) => (child.type === 'text' ? child.text : wordsOf(child))).join('');
    /** Nothing written in the note yet (its title aside): a file comes in only then (the owner's review, 2026-09-28). */
    const isBlank = () => {
        const { doc } = editor.state;
        return doc.childCount === 1 && doc.firstChild.isTextblock && doc.firstChild.content.size === 0;
    };
    /** Import is offered while the note is empty. */
    function showImport() {
        if (!importReady || !editor) return;
        const button = document.querySelector('[data-note-import]');
        if (button) button.hidden = !isBlank();
    }
    /**
     * The file's words become the note: a Markdown file as what it describes, shown full width (it was never
     * laid out for A4 pages), a text file as paragraphs. A note without a title takes the file's first heading,
     * else the file's name.
     */
    function importText(text, name, markdown) {
        if (left) return;
        if (!isBlank()) {
            toast('A file can only come into an empty note. Make a new note for it.', 'info');
            return;
        }
        const doc = markdown ? editor.markdown.parse(text) : textDoc(text);
        let content = doc.content ?? [];
        let title = null;
        if (titleField.value.trim() === '') {
            const first = content[0];
            title = name.replace(/\.[^.]+$/, '');
            if (first?.type === 'heading' && first.attrs?.level === 1 && wordsOf(first).trim()) {
                title = wordsOf(first).trim();
                content = content.slice(1);
            }
        }
        content = withIds(content);
        // Over the most a note can hold (App\Study\NoteDoc::MAX_BYTES), it could never be saved: it doesn't come in.
        if (new Blob([JSON.stringify(compact({ type: 'doc', content }))]).size > Number(host.dataset.maxBytes || Infinity)) {
            toast('That file is too long for one note. Split it into two files, and import each into its own note.', 'info');
            return;
        }
        if (title !== null) {
            titleField.value = title;
            growTitle();
            setTitle();
        }
        if (content.length === 0) {
            toast('There was nothing in the file to bring in.', 'info');
            changed();
            return;
        }
        const chain = editor.chain().insertContentAt({ from: 0, to: editor.state.doc.content.size }, content);
        chain.command(({ tr }) => {
            tr.setMeta('idsGiven', true);
            if (markdown) tr.setDocAttribute('view', 'continuous');
            return true;
        });
        chain.focus('start').run();
        changed();
        toast(`${name || 'The file'} is in the note.`, 'success');
    }
    const importButton = document.querySelector('[data-note-import]');
    const importFile = document.querySelector('[data-import-file]');
    importButton?.addEventListener('click', () => importFile?.click());
    importFile?.addEventListener('change', async () => {
        const file = importFile.files?.[0];
        importFile.value = '';
        if (!file) return;
        if (file.size > Number(host.dataset.maxBytes || Infinity)) {
            toast('That file is too long for one note. Split it into two files, and import each into its own note.', 'info');
            return;
        }
        const markdown = /\.(md|markdown)$/i.test(file.name) || file.type === 'text/markdown';
        importText(await file.text(), file.name, markdown);
    });
    importReady = true;
    showImport();

    // ---------- Formulas ----------
    const formulaLatex = formulaDialog?.querySelector('[data-formula-latex]');
    const formulaPreview = formulaDialog?.querySelector('[data-formula-preview]');
    const formulaRemove = formulaDialog?.querySelector('[data-formula-remove]');
    let formula = { kind: 'inline', pos: null };
    const drawPreview = () => {
        if (!formulaPreview) return;
        const latex = formulaLatex.value.trim();
        if (!latex) {
            formulaPreview.textContent = '';
            return;
        }
        katex.render(latex, formulaPreview, { ...KATEX, displayMode: formula.kind === 'block' });
    };
    /** The panel for a new formula (no position) or an existing one, at its position. */
    function openFormula(kind, node = null, pos = null) {
        if (!formulaDialog) return;
        formula = { kind, pos };
        formulaDialog.querySelector('[data-formula-heading]').textContent = pos === null ? (kind === 'block' ? 'Formula on its own line' : 'Formula in the line') : 'Edit the formula';
        formulaLatex.value = node?.attrs?.latex ?? '';
        formulaRemove.hidden = pos === null;
        drawPreview();
        formulaDialog.showModal();
        formulaLatex.focus();
    }
    formulaLatex?.addEventListener('input', drawPreview);
    formulaLatex?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
            event.preventDefault();
            formulaDialog.querySelector('[data-formula-apply]').click();
        }
    });
    formulaDialog?.querySelector('[data-formula-apply]').addEventListener('click', () => {
        const latex = formulaLatex.value.trim();
        const { kind, pos } = formula;
        formulaDialog.close();
        const chain = editor.chain().focus();
        if (pos === null) {
            if (latex) (kind === 'block' ? chain.insertBlockMath({ latex }) : chain.insertInlineMath({ latex })).run();
        } else if (latex) {
            (kind === 'block' ? chain.updateBlockMath({ latex, pos }) : chain.updateInlineMath({ latex, pos })).run();
        } else {
            (kind === 'block' ? chain.deleteBlockMath({ pos }) : chain.deleteInlineMath({ pos })).run();
        }
    });
    formulaRemove?.addEventListener('click', () => {
        const { kind, pos } = formula;
        formulaDialog.close();
        if (pos === null) return;
        const chain = editor.chain().focus();
        (kind === 'block' ? chain.deleteBlockMath({ pos }) : chain.deleteInlineMath({ pos })).run();
    });
    mathMenu?.addEventListener('click', (event) => {
        const kind = event.target.closest('[data-formula-new]')?.dataset.formulaNew;
        if (!kind) return;
        mathMenu.hidePopover();
        openFormula(kind);
    });

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
            btn.setAttribute('aria-pressed', String(btn.dataset.imageWidth === currentWidth));
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
            // The sizes a note keeps (app/Study/NoteDoc.php): the nearest one.
            const pct = Math.round((newWidthPx / containerWidth) * 100);
            const size = [25, 33, 50, 75, 100].reduce((best, w) => (Math.abs(w - pct) < Math.abs(best - pct) ? w : best));
            figure.dataset.width = `${size}%`;
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

    on(window, 'resize', () => {
        hideImageToolbar();
        scheduleCount();
    });
    const noteContentEl = host.querySelector('.note-content');
    noteContentEl?.addEventListener('scroll', () => {
        if (activeFigureEl) positionImageToolbar(activeFigureEl);
        if (!editor.isFocused) {
            if ((page?.getAttribute('data-page-view') ?? 'pages') === 'pages' && lastTotalPages > 1 && sheetEl) {
                const sheetRect = sheetEl.getBoundingClientRect();
                const containerRect = noteContentEl.getBoundingClientRect();
                const relativeY = (containerRect.top + containerRect.height / 3) - sheetRect.top;
                const pageHeight = 1122.52 * currentZoom;
                const scrolledPage = Math.min(lastTotalPages, Math.max(1, Math.floor(relativeY / pageHeight) + 1));
                const words = editor ? editor.storage.characterCount.words() : 0;
                const chars = editor ? editor.storage.characterCount.characters() : 0;
                if (count) count.textContent = `Page ${scrolledPage} of ${lastTotalPages} · ${words} ${words === 1 ? 'word' : 'words'} · ${chars.toLocaleString()} characters`;
            } else {
                scheduleCount();
            }
        }
    }, { passive: true });
    noteContentEl?.addEventListener('wheel', (event) => {
        if (event.ctrlKey || event.metaKey) {
            event.preventDefault();
            if (event.deltaY < 0) {
                zoomIn();
            } else if (event.deltaY > 0) {
                zoomOut();
            }
        }
    }, { passive: false });
    noteContentEl?.addEventListener('click', (event) => {
        if (event.target === event.currentTarget) {
            editor.commands.focus('end');
        }
    });

    // The page's shortcuts; the note's own are NoteKeys, and a key it used is done with.
    on(document, 'keydown', (event) => {
        if (event.defaultPrevented || document.querySelector('dialog[open]')) return;
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
        if (isMod && (event.key === '+' || event.code === 'NumpadAdd' || (event.key === '=' && event.shiftKey))) {
            event.preventDefault();
            zoomIn();
            return;
        }
        if (isMod && (event.key === '-' || event.code === 'NumpadSubtract')) {
            event.preventDefault();
            zoomOut();
            return;
        }
        if (isMod && (event.key === '0' || event.code === 'Numpad0') && !event.altKey) {
            event.preventDefault();
            resetZoom();
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
        // The page behind doesn't scroll (resources/css/editor.css). Livewire drops it with the next page.
        document.documentElement.toggleAttribute('data-note-full', on);
        // The whole screen where the browser allows it; the whole window everywhere.
        const root = document.documentElement;
        if (on && root.requestFullscreen && !document.fullscreenElement) root.requestFullscreen().catch(() => {});
        if (!on && document.fullscreenElement) document.exitFullscreen().catch(() => {});
        focusButtons[0]?.focus();
    }
    page.querySelector('[data-note-read]').addEventListener('click', () => setReading(!page.hasAttribute('data-reading')));
    focusButtons.forEach((btn) => btn.addEventListener('click', () => setFocus(!page.hasAttribute('data-focus'))));

    // ---------- Document View Mode: Pages (A4) vs Continuous ----------
    // A note keeps its own (NoteDocument, saved with it); a note without one shows this device's last choice.
    const viewToggleButtons = page.querySelectorAll('[data-note-view-toggle]');
    let deviceView = 'pages';
    try {
        deviceView = localStorage.getItem('vistud.note-view-mode') || 'pages';
    } catch {}
    function setPageViewMode(noteView) {
        shownView = noteView ?? null;
        const activeMode = (noteView ?? deviceView) === 'continuous' ? 'continuous' : 'pages';
        page.setAttribute('data-page-view', activeMode);
        viewToggleButtons.forEach((btn) => {
            btn.title = activeMode === 'pages' ? 'Switch to Full Width view' : 'Switch to Pages (A4) view';
        });
        scheduleCount();
    }
    setPageViewMode(editor.state.doc.attrs.view);
    viewReady = true;
    document.fonts?.ready?.then(() => scheduleCount());
    scheduleCount();
    viewToggleButtons.forEach((btn) => {
        btn.addEventListener('click', () => {
            const next = page.getAttribute('data-page-view') === 'pages' ? 'continuous' : 'pages';
            deviceView = next;
            try {
                localStorage.setItem('vistud.note-view-mode', next);
            } catch {}
            // Saved with the note, like its words; not something Undo takes back.
            editor.view.dispatch(editor.state.tr.setDocAttribute('view', next).setMeta('addToHistory', false));
        });
    });

    on(document, 'fullscreenchange', () => {
        if (!inWindow && !document.fullscreenElement && page.hasAttribute('data-focus')) setFocus(false);
    });
    on(document, 'keydown', (event) => {
        const busy = document.querySelector('dialog[open], [data-menu-panel]:not([hidden])');
        if (!inWindow && event.key === 'Escape' && page.hasAttribute('data-focus') && !document.fullscreenElement && !busy) setFocus(false);
    });

    // ---------- Printing and Save as PDF (the owner's review, 2026-09-29) ----------
    // On white paper, as the note looks in the light theme, and named after the note (Save as PDF offers
    // "<title>.pdf"). What the page was is put back once the print dialog closes. resources/css/editor.css
    // leaves only the note on the page.
    // The footer of every page shows the title too (resources/css/editor.css, @page), as a CSS string.
    let beforePrint = null;
    const cssString = (text) => `"${text.replace(/[\\"]/g, '\\$&').replace(/\s+/g, ' ')}"`;
    on(window, 'beforeprint', () => {
        const root = document.documentElement;
        beforePrint ??= { theme: root.dataset.theme, title: document.title };
        if (root.dataset.themeLight) root.dataset.theme = root.dataset.themeLight;
        const title = titleField.value.trim() || 'Untitled note';
        document.title = title;
        root.style.setProperty('--print-title', cssString(title.length > 90 ? `${title.slice(0, 89)}…` : title));
    });
    on(window, 'afterprint', () => {
        if (!beforePrint) return;
        const root = document.documentElement;
        root.dataset.theme = beforePrint.theme;
        root.style.removeProperty('--print-title');
        document.title = beforePrint.title;
        beforePrint = null;
    });

    // ---------- A window of its own (the owner's review, 2026-09-29) ----------
    // The note beside the study material: New window moves it out, Open in ViStud brings it back.
    /** Waits until `done()` holds, for at most `ms`; says whether it did. */
    const until = (done, ms = 5000) => new Promise((resolve) => {
        const started = Date.now();
        const check = () => {
            if (done()) resolve(true);
            else if (Date.now() - started > ms) resolve(false);
            else setTimeout(check, 100);
        };
        check();
    });
    /** Everything written is on the server, so the other window opens all of it (and no draft of this one). */
    function savedForMoving() {
        if (autosave) {
            autosave.flush();
            return until(() => autosave.isClean());
        }
        if (!hasWords()) return Promise.resolve(true);
        createNote();
        return until(() => autosave?.isClean() ?? false);
    }
    page.querySelector('[data-note-pop-out]')?.addEventListener('click', async () => {
        // The window first: browsers allow one only straight after a press. The note goes into it once saved.
        const opened = openNoteWindow(null, note.id ? `vistud-note-${note.id}` : '_blank');
        if (!opened) {
            toast('Your browser blocked the new window. Allow pop-ups for ViStud, then try again.', 'info');
            return;
        }
        const saved = await savedForMoving();
        if (left) return;
        if (!saved) {
            opened.close();
            toast('This note isn\'t saved yet, so it can\'t move to its own window. Try again in a moment.', 'info');
            return;
        }
        // The note's own address, even for one made just now.
        const url = new URL(window.location.href);
        url.searchParams.set('window', '1');
        showInWindow(opened, url.href);
        // This page goes back to where the note lives, free for the study material.
        toast(`“${titleField.value.trim() || 'Untitled note'}” is open in its own window.`, 'success');
        const back = page.querySelector('[data-back]')?.href;
        if (back) window.Livewire ? window.Livewire.navigate(back) : window.location.assign(back);
    });
    page.querySelector('[data-note-pop-in]')?.addEventListener('click', async () => {
        if (!(await savedForMoving())) {
            toast('This note isn\'t saved yet. Try again in a moment.', 'info');
            return;
        }
        const url = new URL(window.location.href);
        url.searchParams.delete('window');
        let main = null;
        try {
            if (window.opener && !window.opener.closed && window.opener.location.origin === window.location.origin) main = window.opener;
        } catch {}
        if (!main) {
            // Opened as a tab of its own, or the main window is gone: this one becomes the note's page.
            window.location.assign(url.href);
            return;
        }
        if (main.Livewire) main.Livewire.navigate(url.href);
        else main.location.assign(url.href);
        main.focus();
        window.close();
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
    if (channel) on(channel, 'message', async ({ data }) => {
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
    on(window, 'vistud:store-drafts', (event) => event.detail.waitFor(autosave ? autosave.storeNow() : Promise.resolve()));

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
    on(document, 'visibilitychange', () => {
        if (document.visibilityState !== 'hidden') return;
        if (autosave) autosave.flush();
        else if (hasWords()) createNote();
    });
    on(window, 'beforeunload', (event) => {
        if (autosave ? autosave.atRisk() : hasWords()) event.preventDefault();
    });

    // Another page, without reloading (resources/js/page.js). Changes that live only in this tab's memory
    // (the browser keeps nothing, or a new note while offline) are worth a question first.
    on(document, 'livewire:navigate', (event) => {
        if (event.detail?.history) return;
        const atRisk = autosave ? autosave.atRisk() : hasWords() && !navigator.onLine;
        if (atRisk && !window.confirm('This note has changes that aren\'t saved anywhere yet. Leave anyway?')) event.preventDefault();
    });
    // Leaving: what's written is stored and sent, then the editor stops.
    on(document, 'livewire:navigating', () => {
        clearTimeout(createTimer);
        if (autosave) autosave.leave();
        else if (hasWords()) createNote();
        left = true;
        leaving.abort();
        clearTimeout(countTimer);
        document.documentElement.removeAttribute('data-note-full');
        editor.destroy();
    });

    // Opened from a Markdown or text file: its words become the note's, and its name the title, then the first save makes the note.
    if (host.dataset.importUrl && !left) {
        try {
            const response = await fetch(host.dataset.importUrl, { credentials: 'same-origin' });
            if (!response.ok) throw new Error(String(response.status));
            importText(await response.text(), host.dataset.importName ?? '', host.dataset.importKind === 'markdown');
        } catch {
            toast('The file couldn\'t be read into the note. Open it again from its page.', 'info');
        }
    }

    host.dataset.ready = 'true';
    return editor;
}
