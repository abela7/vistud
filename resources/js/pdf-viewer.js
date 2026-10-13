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

/**
 * How long PDF.js gets to open a document. Where workers never start (some embedded browsers, Cursor's
 * among them) it waits silently for ever, so after this the browser's own viewer takes over.
 */
const OPEN_TIMEOUT_MS = 8000;

function getPdfStorageKey(rawUrl) {
    if (!rawUrl) return null;
    try {
        const parsed = new URL(rawUrl, window.location.origin);
        return 'vistud-pdf-pos-' + parsed.pathname;
    } catch {
        return 'vistud-pdf-pos-' + encodeURIComponent(rawUrl.split('?')[0]);
    }
}

/** Replaces the frame with the PDF drawn page by page. Says 'pdf-shown' (bubbling) once something is there. */
export async function drawPdf(frame) {
    const url = frame.dataset.pdfSrc;
    const downloadUrl = frame.dataset.downloadSrc || url;
    const view = document.createElement('div');
    const existingClasses = (frame.getAttribute('class') ?? '')
        .split(/\s+/)
        .filter((c) => c && c !== 'opacity-0');
    view.className = [...existingClasses, 'pdf-view'].join(' ').trim();
    view.setAttribute('role', 'document');
    view.setAttribute('aria-label', frame.title);
    view.tabIndex = 0;
    frame.replaceWith(view);
    const shown = () => {
        view.dataset.shown = '';
        view.classList.remove('opacity-0');
        view.dispatchEvent(new CustomEvent('pdf-shown', { bubbles: true }));
    };

    let pdf;
    const loading = getDocument({ url, withCredentials: true, isEvalSupported: false });
    let timer;
    try {
        pdf = await Promise.race([
            loading.promise,
            new Promise((resolve, reject) => {
                timer = setTimeout(() => reject(new Error('PDF.js did not open the document in time.')), OPEN_TIMEOUT_MS);
            }),
        ]);
    } catch {
        loading.destroy();
        if (navigator.pdfViewerEnabled) {
            const native = document.createElement('iframe');
            native.className = view.className.replace('pdf-view', '').trim();
            native.title = frame.title;
            // The browser's viewer takes its zoom from the address (#zoom=150), so a new zoom opens it again at it.
            const startZoom = Number(getComputedStyle(view).getPropertyValue('--doc-zoom')) || 1;
            native.src = `${url}#zoom=${Math.round(startZoom * 100)}`;
            window.addEventListener('file-zoom', (event) => {
                if (native.isConnected) native.src = `${url}#zoom=${Math.round(event.detail.zoom * 100)}`;
            });
            native.dataset.shown = '';
            view.replaceWith(native);
            native.dispatchEvent(new CustomEvent('pdf-shown', { bubbles: true }));
            return;
        }
        const problem = document.createElement('p');
        problem.className = 'pdf-view-problem';
        problem.textContent = 'This document can\'t be shown here. ';
        const open = document.createElement('a');
        open.className = 'btn btn-secondary btn-sm inline-flex items-center gap-1.5 ml-2';
        open.href = downloadUrl;
        open.target = '_blank';
        open.rel = 'noopener';
        open.textContent = 'Download file';
        problem.append(open);
        view.replaceChildren(problem);
        shown();
        return;
    } finally {
        clearTimeout(timer);
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

    const storageKey = getPdfStorageKey(url);
    let isRestored = false;

    const restorePosition = () => {
        if (!storageKey) {
            isRestored = true;
            return;
        }
        if (view.clientHeight <= 0 || view.clientWidth <= 0) {
            return;
        }
        try {
            const raw = localStorage.getItem(storageKey);
            if (raw) {
                const saved = JSON.parse(raw);
                if (saved && typeof saved.page === 'number' && saved.page >= 1 && saved.page <= pdf.numPages) {
                    const target = holders[saved.page - 1];
                    if (target) {
                        const pageTop = target.offsetTop;
                        const offset = (typeof saved.offsetPercent === 'number' && saved.offsetPercent > 0)
                            ? Math.round(target.offsetHeight * saved.offsetPercent)
                            : 0;
                        view.scrollTop = pageTop + offset;
                    }
                }
            }
        } catch {}
        setTimeout(() => {
            isRestored = true;
        }, 300);
    };

    requestAnimationFrame(restorePosition);

    if (storageKey) {
        let scrollTimeout = null;
        view.addEventListener('scroll', () => {
            if (!isRestored) return;
            if (view.clientHeight <= 0 || view.clientWidth <= 0) return;
            clearTimeout(scrollTimeout);
            scrollTimeout = setTimeout(() => {
                if (view.clientHeight <= 0 || view.clientWidth <= 0) return;
                const viewTop = view.scrollTop;
                let activePage = 1;
                let offsetPercent = 0;
                for (let i = 0; i < holders.length; i++) {
                    const h = holders[i];
                    const top = h.offsetTop;
                    const height = h.offsetHeight;
                    if (viewTop >= top - 20 && viewTop < top + height) {
                        activePage = i + 1;
                        offsetPercent = height > 0 ? Math.max(0, viewTop - top) / height : 0;
                        break;
                    }
                    if (viewTop < top) {
                        activePage = Math.max(1, i);
                        break;
                    }
                }
                try {
                    localStorage.setItem(storageKey, JSON.stringify({
                        page: activePage,
                        offsetPercent: Math.round(offsetPercent * 1000) / 1000,
                        scrollTop: Math.round(viewTop),
                    }));
                } catch {}
            }, 150);
        }, { passive: true });
    }

    // The zoom of the file's page (--doc-zoom on .file-pane-doc, set by its zoom bar): the pages are that much
    // wider than the frame, so they are drawn sharper too, up to twice the usual limit.
    const zoomNow = () => Number(getComputedStyle(view).getPropertyValue('--doc-zoom')) || 1;

    async function draw(holder) {
        const width = holder.clientWidth;
        if (!width || holder.dataset.drawnAt === String(width)) return;
        holder.dataset.drawnAt = String(width);
        const page = await pdf.getPage(Number(holder.dataset.page));
        const base = page.getViewport({ scale: 1 });
        holder.style.aspectRatio = `${base.width} / ${base.height}`;
        const limit = MAX_SCALE * Math.min(2, Math.max(1, zoomNow()));
        const viewport = page.getViewport({ scale: Math.min(limit, (width * (window.devicePixelRatio || 1)) / base.width) });
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
        if (!isRestored && view.clientHeight > 0 && view.clientWidth > 0) {
            restorePosition();
        }
        clearTimeout(resizing);
        resizing = setTimeout(() => holders.forEach((holder) => {
            observer.unobserve(holder);
            observer.observe(holder);
        }), 200);
    }).observe(view);

    // A new zoom: the same spot stays in the middle of the frame, and the pages near it are drawn at the new width.
    window.addEventListener('file-zoom', () => {
        if (!view.isConnected) return;
        const middleY = (view.scrollTop + view.clientHeight / 2) / (view.scrollHeight || 1);
        const middleX = (view.scrollLeft + view.clientWidth / 2) / (view.scrollWidth || 1);
        requestAnimationFrame(() => {
            view.scrollTop = middleY * view.scrollHeight - view.clientHeight / 2;
            view.scrollLeft = middleX * view.scrollWidth - view.clientWidth / 2;
            holders.forEach((holder) => {
                observer.unobserve(holder);
                observer.observe(holder);
            });
        });
    });
    shown();
}
