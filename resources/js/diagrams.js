/*
| Diagrams and formulas in the tutor's finished replies (resources/js/tutor-chat.js loads this only when a reply
| has one). A ```mermaid block is drawn by Mermaid in the theme's own colours, read from its tokens, and drawn again
| when the theme changes; the diagram's text stays one click away under it, for screen readers and for copying. A
| block Mermaid can't read stays as code and says so. $…$ and $$…$$ formulas are drawn by KaTeX, as on a Markdown
| file's page.
*/

import { drawFormulas } from './formulas.js';

let engine = null;
let drawn = 0;

const token = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();

/** Mermaid's colours, from the theme's tokens: the diagram looks like the rest of the page, light or dark. */
function colours() {
    const [surface, sunken, raised, text, muted, accent, subtle, border, strong] = ['--surface', '--surface-sunken', '--surface-raised', '--text', '--text-muted', '--accent', '--accent-subtle', '--border', '--border-strong'].map(token);
    return {
        darkMode: document.documentElement.style.colorScheme === 'dark',
        background: surface,
        fontFamily: getComputedStyle(document.body).fontFamily,
        fontSize: '14px',
        textColor: text,
        lineColor: muted,
        primaryColor: subtle,
        primaryTextColor: text,
        primaryBorderColor: accent,
        secondaryColor: sunken,
        secondaryTextColor: text,
        secondaryBorderColor: strong,
        tertiaryColor: raised,
        tertiaryTextColor: text,
        tertiaryBorderColor: border,
        mainBkg: subtle,
        nodeBorder: accent,
        clusterBkg: sunken,
        clusterBorder: border,
        edgeLabelBackground: surface,
        noteBkgColor: sunken,
        noteTextColor: text,
        noteBorderColor: strong,
        actorBkg: subtle,
        actorBorder: accent,
        actorTextColor: text,
        actorLineColor: muted,
        signalColor: muted,
        signalTextColor: text,
        labelBoxBkgColor: sunken,
        labelBoxBorderColor: border,
        labelTextColor: text,
        loopTextColor: text,
        attributeBackgroundColorOdd: surface,
        attributeBackgroundColorEven: sunken,
    };
}

async function mermaid() {
    engine ??= import('mermaid').then((module) => module.default);
    const loaded = await engine;
    loaded.initialize({ startOnLoad: false, securityLevel: 'strict', theme: 'base', themeVariables: colours(), flowchart: { htmlLabels: false }, fontFamily: getComputedStyle(document.body).fontFamily });
    return loaded;
}

async function draw(figure, source, mermaidEngine) {
    const { svg } = await mermaidEngine.render(`vistud-diagram-${++drawn}`, source);
    const drawing = figure.querySelector('.chat-diagram-drawing');
    drawing.innerHTML = svg;
    // On a phone, a wide diagram keeps a readable size and scrolls sideways in its box instead of shrinking.
    const natural = drawing.querySelector('svg')?.style.maxWidth;
    if (natural) drawing.querySelector('svg').style.minWidth = `min(${natural}, 36rem)`;
}

/** Draws the diagrams not drawn yet under root, and the formulas. */
export async function drawDiagrams(root) {
    const blocks = [...root.querySelectorAll('pre > code.language-mermaid:not([data-tried])')];
    if (blocks.length > 0) {
        const loaded = await mermaid();
        for (const code of blocks) {
            code.dataset.tried = '';
            const source = code.textContent;
            const figure = document.createElement('figure');
            figure.className = 'chat-diagram';
            figure.dataset.source = source;
            figure.innerHTML = '<div class="chat-diagram-drawing" tabindex="0" role="group" aria-label="Diagram"></div><details><summary>The diagram as text</summary><pre></pre></details>';
            figure.querySelector('pre').textContent = source;
            try {
                await draw(figure, source, loaded);
                code.parentElement.replaceWith(figure);
            } catch {
                const note = document.createElement('p');
                note.className = 'chat-diagram-failed';
                note.textContent = 'This diagram couldn\'t be drawn: here is its text.';
                code.parentElement.before(note);
            }
        }
    }
    if (root.textContent.includes('$')) drawFormulas(root);
}

/** Draws every diagram again, in the theme's new colours. */
export async function redrawDiagrams(root) {
    const figures = [...root.querySelectorAll('figure.chat-diagram[data-source]')];
    if (figures.length === 0) return;
    const loaded = await mermaid();
    for (const figure of figures) {
        try {
            await draw(figure, figure.dataset.source, loaded);
        } catch {}
    }
}
