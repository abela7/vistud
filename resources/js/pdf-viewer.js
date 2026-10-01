/*
| A PDF where the browser can't show one inside the page (the owner's review, 2026-10-01): phones and tablets
| (Android shows nothing, an iPhone only the first page). There PDF.js draws it, page by page as the pages
| come near the screen, as wide as the screen; pages far away are let go, so a long lecture never fills the
| phone's memory. A computer keeps its own PDF viewer (search, zoom, print). Word, PowerPoint and Excel
| previews are PDFs too (App\Study\FilePreviews). Loaded only on a file's page that needs it (app.js).
*/

import { GlobalWorkerOptions, getDocument } from 'pdfjs-dist/legacy/build/pdf.mjs';
import workerUrl from 'pdfjs-dist/legacy/build/pdf.worker.min.mjs?url';

GlobalWorkerOptions.workerSrc = workerUrl;

/** Device pixels per PDF point at most: sharp on a phone, without canvases too big for it. */
const MAX_SCALE = 2.5;

/** Replaces the frame with the PDF drawn page by page. Says 'pdf-shown' (bubbling) once something is there. */
export async function drawPdf(frame) {
    const url = frame.dataset.pdfSrc;
    const view = document.createElement('div');
    view.className = `${frame.getAttribute('class') ?? ''} pdf-view`.trim();
    view.setAttribute('role', 'document');
    view.setAttribute('aria-label', frame.title);
    view.tabIndex = 0;
    frame.replaceWith(view);
    const shown = () => view.dispatchEvent(new CustomEvent('pdf-shown', { bubbles: true }));

    let pdf;
    try {
        pdf = await getDocument({ url, withCredentials: true, isEvalSupported: false }).promise;
    } catch {
        const problem = document.createElement('p');
        problem.className = 'pdf-view-problem';
        problem.textContent = 'This document can\'t be shown here. ';
        const open = document.createElement('a');
        open.href = url;
        open.target = '_blank';
        open.rel = 'noopener';
        open.textContent = 'Open it';
        problem.append(open);
        view.replaceChildren(problem);
        shown();
        return;
    }

    // Every page has its place at once (the first page's shape until its own is known), so the scroll bar is right.
    const first = (await pdf.getPage(1)).getViewport({ scale: 1 });
    const holders = [];
    for (let number = 1; number <= pdf.numPages; number++) {
        const holder = document.createElement('div');
        holder.className = 'pdf-page';
        holder.dataset.page = String(number);
        holder.style.aspectRatio = `${first.width} / ${first.height}`;
        holder.setAttribute('aria-label', `Page ${number} of ${pdf.numPages}`);
        holders.push(holder);
    }
    view.replaceChildren(...holders);

    async function draw(holder) {
        const width = holder.clientWidth;
        if (!width || holder.dataset.drawnAt === String(width)) return;
        holder.dataset.drawnAt = String(width);
        const page = await pdf.getPage(Number(holder.dataset.page));
        const base = page.getViewport({ scale: 1 });
        holder.style.aspectRatio = `${base.width} / ${base.height}`;
        const viewport = page.getViewport({ scale: Math.min(MAX_SCALE, (width * (window.devicePixelRatio || 1)) / base.width) });
        const canvas = document.createElement('canvas');
        canvas.width = Math.floor(viewport.width);
        canvas.height = Math.floor(viewport.height);
        try {
            await page.render({ canvas, viewport }).promise;
        } catch {
            delete holder.dataset.drawnAt;
            return;
        }
        // Still wanted at this width: not scrolled far away or redrawn for a turned phone meanwhile.
        if (holder.dataset.drawnAt === String(width)) holder.replaceChildren(canvas);
    }
    function forget(holder) {
        delete holder.dataset.drawnAt;
        holder.replaceChildren();
    }

    const observer = new IntersectionObserver((entries) => {
        for (const entry of entries) (entry.isIntersecting ? draw : forget)(entry.target);
    }, { root: view, rootMargin: '150% 0px' });
    holders.forEach((holder) => observer.observe(holder));

    // A turned phone or a moved split: the pages near the screen are drawn again at the new width.
    let resizing = null;
    new ResizeObserver(() => {
        clearTimeout(resizing);
        resizing = setTimeout(() => holders.forEach((holder) => {
            observer.unobserve(holder);
            observer.observe(holder);
        }), 200);
    }).observe(view);
    shown();
}
