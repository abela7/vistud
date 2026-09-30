/*
| The upload dialog (resources/views/livewire/workspaces/partials/dialog.blade.php; the owner's review,
| 2026-09-30). Files are chosen, or a whole folder, or dropped (files and folders), and each is sent on its
| own to POST /api/v1/files, one after another: each shows its own progress and its own answer, and no request
| carries more than one file. A file from a folder goes into the folders it was in, made where they don't
| exist. System files (.DS_Store, Thumbs.db, ~$ lock files, anything in a hidden folder) are left out.
*/

const xsrf = () => decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.slice(11) ?? '');

const SKIPPED = new Set(['thumbs.db', 'desktop.ini', '.ds_store']);

/** A file the student wouldn't mean to upload: the system's own, or one in a hidden folder. */
function systemFile(path) {
    return path.split('/').some((part) => part.startsWith('.') || part.startsWith('~$') || SKIPPED.has(part.toLowerCase()));
}

function size(bytes) {
    if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1).replace(/\.0$/, '')} MB`;
}

/** Every file under a dropped folder, with its path from the folder (Week 1/Lectures/Lecture 1.pdf). */
async function filesIn(entry) {
    if (entry.isFile) {
        const file = await new Promise((resolve, reject) => entry.file(resolve, reject));
        return [{ file, path: entry.fullPath.replace(/^\/+/, '') }];
    }
    const reader = entry.createReader();
    const found = [];
    // Folders come in batches: read until one comes back empty.
    for (;;) {
        const batch = await new Promise((resolve, reject) => reader.readEntries(resolve, reject));
        if (batch.length === 0) break;
        for (const child of batch) found.push(...(await filesIn(child)));
    }
    return found;
}

export function uploader({ url, placeType, placeId, maxBytes, extensions }) {
    return {
        items: [],
        over: false,
        running: false,
        uploaded: 0,
        refused: 0,
        reported: [0, 0],

        get summary() {
            if (this.items.length === 0) return '';
            const left = this.items.filter((item) => item.state === 'waiting' || item.state === 'sending').length;
            if (left > 0) return `Uploading ${this.items.length - left + 1} of ${this.items.length}…`;
            const parts = [`${this.uploaded} ${this.uploaded === 1 ? 'file' : 'files'} uploaded`];
            if (this.refused > 0) parts.push(`${this.refused} not uploaded`);
            return `${parts.join(', ')}.`;
        },

        choose(event) {
            const chosen = [...event.target.files].map((file) => ({ file, path: file.webkitRelativePath || file.name }));
            event.target.value = '';
            this.add(chosen);
        },

        async drop(event) {
            this.over = false;
            // The entries must be taken while the drop is happening; the files are read after.
            const entries = [...(event.dataTransfer?.items ?? [])].map((item) => item.webkitGetAsEntry?.()).filter(Boolean);
            if (entries.length === 0) {
                this.add([...(event.dataTransfer?.files ?? [])].map((file) => ({ file, path: file.name })));
                return;
            }
            const found = [];
            for (const entry of entries) found.push(...(await filesIn(entry)));
            this.add(found);
        },

        add(chosen) {
            for (const { file, path } of chosen) {
                if (systemFile(path)) continue;
                const cut = path.lastIndexOf('/');
                const extension = file.name.includes('.') ? file.name.split('.').pop().toLowerCase() : '';
                const item = {
                    key: `${Date.now()}-${Math.random()}`,
                    file,
                    path: path.replaceAll('/', ' › '),
                    folder: cut > 0 ? path.slice(0, cut) : '',
                    state: 'waiting',
                    progress: 0,
                    note: size(file.size),
                };
                if (!extensions.includes(extension)) [item.state, item.note] = ['refused', 'This kind of file can\'t be uploaded.'];
                else if (file.size === 0) [item.state, item.note] = ['refused', 'This file is empty.'];
                else if (file.size > maxBytes) [item.state, item.note] = ['refused', `Bigger than ${size(maxBytes)}, the most this computer takes.`];
                if (item.state === 'refused') this.refused++;
                this.items.push(item);
            }
            this.run();
        },

        async run() {
            if (this.running) return;
            this.running = true;
            let item;
            while ((item = this.items.find((candidate) => candidate.state === 'waiting'))) {
                await this.send(item);
            }
            this.running = false;
            // This round's files: the list is drawn again with them, and with none refused the dialog closes.
            const [uploaded, refused] = [this.uploaded - this.reported[0], this.refused - this.reported[1]];
            this.reported = [this.uploaded, this.refused];
            this.$wire.uploadsFinished(uploaded, refused);
        },

        send(item) {
            item.state = 'sending';
            return new Promise((resolve) => {
                const form = new FormData();
                form.append('file', item.file, item.file.name);
                form.append('place_type', placeType);
                form.append('place_id', placeId);
                if (item.folder) form.append('folder', item.folder);
                const request = new XMLHttpRequest();
                request.open('POST', url);
                request.setRequestHeader('Accept', 'application/json');
                request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                request.setRequestHeader('X-XSRF-TOKEN', xsrf());
                request.upload.addEventListener('progress', (event) => {
                    if (event.lengthComputable) item.progress = Math.round((event.loaded / event.total) * 100);
                });
                const finish = (state, note) => {
                    [item.state, item.note] = [state, note];
                    if (state === 'done') this.uploaded++;
                    else this.refused++;
                    resolve();
                };
                request.addEventListener('load', () => {
                    if (request.status === 201) return finish('done', 'Uploaded');
                    let error = null;
                    try {
                        error = JSON.parse(request.responseText).error;
                    } catch {}
                    const fields = error?.details?.fields ?? {};
                    const reason = {
                        413: `Bigger than this computer takes (${size(maxBytes)}).`,
                        419: 'Your session has ended. Reload the page and try again.',
                    }[request.status] ?? fields.file?.[0] ?? fields.name?.[0] ?? fields.place?.[0] ?? error?.message ?? 'It couldn\'t be uploaded. Try it again.';
                    finish('refused', reason);
                });
                request.addEventListener('error', () => finish('refused', 'ViStud couldn\'t be reached. Check the connection and try again.'));
                request.send(form);
            });
        },
    };
}

const register = () => window.Alpine?.data('uploader', uploader);
if (window.Alpine) register();
else document.addEventListener('alpine:init', register);
