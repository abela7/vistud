/*
| Grammar and spelling in notes (the owner's ask, 2026-10-26), by Harper (harper.js, Apache-2.0): it runs on the
| student's own device, in a worker, so nothing is sent anywhere and no AI is used. Words, grammar, punctuation and
| style are underlined; a click on one shows what is wrong, its fixes, Ignore, and Add to my words. The toolbar's
| Grammar button turns it on and off; it is remembered on this device. Off, Harper is never even loaded (about 8 MB
| the first time, then cached). The English is the browser's own (British unless it says US, Australian, Canadian or
| Indian). Code, formulas and code blocks are left alone.
*/

import { Extension } from '@tiptap/core';
import { Plugin, PluginKey } from '@tiptap/pm/state';
import { Decoration, DecorationSet } from '@tiptap/pm/view';

const KEY = new PluginKey('grammar');
const STORE = { on: 'vistud.grammar', words: 'vistud.grammar.words' };
/** How long typing must pause before the note is checked again, in ms. */
const PAUSE = 700;
/** Fixes offered on one underline, at most. */
const MOST = 4;
/** Lint kinds drawn in the error colour; the rest (style, readability, …) are suggestions. */
const ERRORS = new Set(['Spelling', 'Typo', 'Grammar', 'Agreement', 'Punctuation', 'Capitalization', 'Repetition', 'BoundaryError', 'Malapropism', 'Eggcorn', 'WordOrder']);

const read = (key, fallback) => {
    try {
        return localStorage.getItem(key) ?? fallback;
    } catch {
        return fallback;
    }
};
const write = (key, value) => {
    try {
        localStorage.setItem(key, value);
    } catch {
        // Private windows: it still works, it just isn't remembered.
    }
};
const myWords = () => {
    try {
        const words = JSON.parse(read(STORE.words, '[]'));
        return Array.isArray(words) ? words.filter((w) => typeof w === 'string') : [];
    } catch {
        return [];
    }
};

/** The English of the browser, as Harper names it. */
function dialectName() {
    const tag = (navigator.languages?.[0] ?? navigator.language ?? '').toLowerCase();
    if (tag.startsWith('en-us')) return 'American';
    if (tag.startsWith('en-au')) return 'Australian';
    if (tag.startsWith('en-ca')) return 'Canadian';
    if (tag.startsWith('en-in')) return 'Indian';

    return 'British';
}

let started = null;
/** One Harper for the page, loaded the first time it is needed. */
function harper() {
    started ??= (async () => {
        const [{ WorkerLinter, Dialect }, { slimBinary }] = await Promise.all([import('harper.js'), import('harper.js/slimBinary')]);
        const linter = new WorkerLinter({ binary: slimBinary, dialect: Dialect[dialectName()] });
        await linter.setup();
        const words = myWords();
        if (words.length > 0) await linter.importWords(words);

        return linter;
    })();

    return started;
}

/**
 * The note's prose as one string, a blank line between blocks, with where each block starts in it and in the
 * document. Inline code, a formula or a picture stands in as letters of the same length (a space would read as a
 * gap), and what is found there is dropped (`skip`): a place in a block's text is its place in the document.
 */
function proseOf(doc) {
    const blocks = [];
    const skip = [];
    let text = '';
    doc.descendants((node, pos) => {
        if (node.type.spec.code || node.type.name.toLowerCase().includes('math')) return false;
        if (!node.isTextblock) return true;
        let block = '';
        node.forEach((child, offset) => {
            const code = !child.isText || child.marks.some((m) => m.type.name === 'code');
            if (code) skip.push([pos + 1 + offset, pos + 1 + offset + child.nodeSize]);
            block += code ? 'x'.repeat(child.nodeSize) : child.text;
        });
        if (block.trim() !== '') {
            blocks.push({ at: text.length, pos: pos + 1, length: block.length });
            text += block + '\n\n';
        }

        return false;
    });

    return { text, blocks, skip };
}

/** Harper counts characters (code points); a JavaScript string counts UTF-16 units. Index i of the one, in the other. */
function unitsOf(text) {
    const at = [0];
    let units = 0;
    for (const char of text) {
        units += char.length;
        at.push(units);
    }

    return at;
}

/** Where a span of the joined prose is in the document, or null when it crosses from one block to the next. */
function placeOf(blocks, from, to) {
    let lo = 0;
    let hi = blocks.length - 1;
    while (lo <= hi) {
        const mid = (lo + hi) >> 1;
        const block = blocks[mid];
        if (from < block.at) hi = mid - 1;
        else if (from >= block.at + block.length) lo = mid + 1;
        else return to <= block.at + block.length ? { from: block.pos + from - block.at, to: block.pos + to - block.at } : null;
    }

    return null;
}

export const Grammar = Extension.create({
    name: 'grammar',

    addOptions() {
        return { host: null };
    },

    addStorage() {
        return { on: read(STORE.on, 'on') !== 'off', ignored: new Set(), timer: null, round: 0, card: null };
    },

    addProseMirrorPlugins() {
        const ext = this;

        return [
            new Plugin({
                key: KEY,
                state: {
                    init: () => ({ decorations: DecorationSet.empty, found: [] }),
                    apply(tr, old) {
                        const next = tr.getMeta(KEY);
                        if (next) return next;
                        if (!tr.docChanged) return old;

                        return { decorations: old.decorations.map(tr.mapping, tr.doc), found: old.found.map((f) => ({ ...f, from: tr.mapping.map(f.from), to: tr.mapping.map(f.to, -1) })) };
                    },
                },
                props: {
                    decorations: (state) => KEY.getState(state).decorations,
                    handleClick: (view, pos, event) => {
                        const mark = event.target instanceof Element ? event.target.closest('.grammar-mark') : null;
                        if (!mark) return false;
                        const found = KEY.getState(view.state).found.find((f) => pos >= f.from && pos <= f.to);
                        if (found) ext.storage.show?.(found, mark);

                        return false;
                    },
                },
                view: () => ({
                    update: (view, prev) => {
                        if (ext.storage.on && !view.state.doc.eq(prev.doc)) ext.storage.later?.();
                    },
                }),
            }),
        ];
    },

    onCreate() {
        const { editor, storage, options } = this;
        const host = options.host;
        const toggle = host?.querySelector('[data-grammar-toggle]');

        const clear = () => editor.view.dispatch(editor.state.tr.setMeta(KEY, { decorations: DecorationSet.empty, found: [] }));
        const check = async () => {
            if (!storage.on || editor.isDestroyed) return;
            const round = ++storage.round;
            const { text, blocks, skip } = proseOf(editor.state.doc);
            let lints;
            try {
                lints = await (await harper()).lint(text, { language: 'plaintext' });
            } catch {
                // Harper could not load (an old browser, or offline the first time): notes work as before.
                if (toggle) toggle.title = 'Grammar check could not start in this browser';

                return;
            }
            // Typed on meanwhile, or turned off: this answer is for an older note.
            if (round !== storage.round || !storage.on || editor.isDestroyed) return;
            const units = unitsOf(text);
            const found = [];
            for (const lint of lints) {
                const span = lint.span();
                const place = placeOf(blocks, units[span.start] ?? span.start, units[span.end] ?? span.end);
                const problem = lint.get_problem_text();
                const kind = lint.lint_kind();
                const key = `${kind}|${problem}|${lint.message()}`;
                const inCode = place && skip.some(([from, to]) => place.from < to && place.to > from);
                if (place && place.from < place.to && !inCode && !storage.ignored.has(key)) {
                    found.push({
                        ...place, key, problem, kind,
                        label: lint.lint_kind_pretty(),
                        message: lint.message(),
                        fixes: lint.suggestions().slice(0, MOST).map((s) => ({ text: s.get_replacement_text(), kind: s.kind() })),
                    });
                }
                lint.free?.();
            }
            const decorations = DecorationSet.create(editor.state.doc, found.map((f) => Decoration.inline(f.from, f.to, {
                class: `grammar-mark ${ERRORS.has(f.kind) ? 'is-error' : 'is-suggestion'}`,
                title: f.message,
            })));
            editor.view.dispatch(editor.state.tr.setMeta(KEY, { decorations, found }).setMeta('addToHistory', false));
        };
        storage.later = () => {
            clearTimeout(storage.timer);
            storage.timer = setTimeout(check, PAUSE);
        };

        const close = () => {
            storage.card?.remove();
            storage.card = null;
        };
        storage.show = (found, mark) => {
            close();
            const card = document.createElement('div');
            card.className = 'grammar-card';
            card.setAttribute('role', 'dialog');
            card.setAttribute('aria-label', found.label);
            const title = document.createElement('p');
            title.className = 'grammar-card-kind';
            title.textContent = found.label;
            const message = document.createElement('p');
            message.className = 'grammar-card-message';
            message.textContent = found.message;
            card.append(title, message);
            const row = document.createElement('div');
            row.className = 'grammar-card-fixes';
            for (const fix of found.fixes) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'grammar-fix';
                // Harper's kinds: 0 replace, 1 remove, 2 insert after the problem.
                button.textContent = fix.kind === 1 ? `Remove "${found.problem}"` : fix.kind === 2 ? `Add "${fix.text}"` : fix.text;
                button.addEventListener('click', () => {
                    close();
                    const chain = editor.chain().focus();
                    if (fix.kind === 1) chain.deleteRange({ from: found.from, to: found.to }).run();
                    else if (fix.kind === 2) chain.insertContentAt(found.to, fix.text).run();
                    else chain.insertContentAt({ from: found.from, to: found.to }, fix.text).run();
                });
                row.append(button);
            }
            if (found.fixes.length > 0) card.append(row);
            const actions = document.createElement('div');
            actions.className = 'grammar-card-actions';
            const ignore = document.createElement('button');
            ignore.type = 'button';
            ignore.className = 'grammar-action';
            ignore.textContent = 'Ignore';
            ignore.addEventListener('click', () => {
                close();
                storage.ignored.add(found.key);
                check();
            });
            actions.append(ignore);
            if (found.kind === 'Spelling' && /^[\p{L}\p{N}'’-]+$/u.test(found.problem)) {
                const add = document.createElement('button');
                add.type = 'button';
                add.className = 'grammar-action';
                add.textContent = `Add "${found.problem}" to my words`;
                add.addEventListener('click', async () => {
                    close();
                    write(STORE.words, JSON.stringify([...new Set([...myWords(), found.problem])]));
                    await (await harper()).importWords([found.problem]);
                    check();
                });
                actions.append(add);
            }
            card.append(actions);
            document.body.append(card);
            const box = mark.getBoundingClientRect();
            const width = card.offsetWidth;
            card.style.left = `${Math.max(8, Math.min(box.left, window.innerWidth - width - 8))}px`;
            const below = box.bottom + 6;
            card.style.top = `${below + card.offsetHeight > window.innerHeight - 8 ? Math.max(8, box.top - card.offsetHeight - 6) : below}px`;
            storage.card = card;
            card.querySelector('button')?.focus({ preventScroll: true });
        };
        storage.onKey = (event) => {
            if (event.key === 'Escape' && storage.card) {
                close();
                editor.commands.focus();
            }
        };
        storage.onPointer = (event) => {
            if (storage.card && !storage.card.contains(event.target) && !(event.target instanceof Element && event.target.closest('.grammar-mark'))) close();
        };
        document.addEventListener('keydown', storage.onKey);
        document.addEventListener('pointerdown', storage.onPointer);
        window.addEventListener('scroll', close, { passive: true, capture: true });
        storage.onScroll = close;

        const show = () => {
            toggle?.setAttribute('aria-pressed', String(storage.on));
            if (toggle) toggle.title = storage.on ? 'Grammar check: on (click to turn off)' : 'Grammar check: off (click to turn on)';
        };
        toggle?.addEventListener('click', () => {
            storage.on = !storage.on;
            write(STORE.on, storage.on ? 'on' : 'off');
            show();
            if (storage.on) {
                check();
            } else {
                storage.round++;
                close();
                clear();
            }
        });
        show();
        if (storage.on) check();
    },

    onDestroy() {
        const { storage } = this;
        clearTimeout(storage.timer);
        storage.round++;
        storage.card?.remove();
        document.removeEventListener('keydown', storage.onKey);
        document.removeEventListener('pointerdown', storage.onPointer);
        window.removeEventListener('scroll', storage.onScroll, { capture: true });
    },
});
