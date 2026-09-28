/*
| Formulas in a Markdown file shown on its page (App\Study\MarkdownPreview):
| $…$ in a line and $$…$$ on its own are drawn by KaTeX, once the page has
| any. Loaded only then (resources/js/app.js), like the note editor.
*/

import renderMathInElement from 'katex/contrib/auto-render';
import 'katex/dist/katex.min.css';

export function drawFormulas(root) {
    renderMathInElement(root, {
        delimiters: [
            { left: '$$', right: '$$', display: true },
            { left: '$', right: '$', display: false },
        ],
        throwOnError: false,
        strict: 'ignore',
        trust: false,
        maxSize: 50,
        errorColor: 'currentColor',
        ignoredTags: ['script', 'noscript', 'style', 'textarea', 'pre', 'code', 'a'],
    });
}
