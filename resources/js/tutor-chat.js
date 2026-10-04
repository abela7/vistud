/*
| The tutor's chat on a session page (resources/views/livewire/workspaces/tutor-chat.blade.php,
| App\Livewire\Workspaces\TutorChat). The student's words show at once and the answer streams in (wire:stream);
| the log follows it unless the student has scrolled up. Notes and files from the module can be attached to a
| message (four at most), and a file or picture chosen, pasted or dropped is uploaded first, one at a time,
| into the module's "From the chat" folder (POST /api/v1/files, as the upload dialog does). Words and attachments
| the chat refused come back to the box.
*/

const MOST = 4;
const xsrf = () => decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.slice(11) ?? '');
const size = (bytes) => (bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / (1024 * 1024)).toFixed(1).replace(/\.0$/, '')} MB`);

function uploadOne(upload, file) {
    return new Promise((resolve, reject) => {
        const form = new FormData();
        form.append('file', file, file.name);
        form.append('place_type', upload.type);
        form.append('place_id', upload.id);
        form.append('folder', 'From the chat');
        const request = new XMLHttpRequest();
        request.open('POST', upload.url);
        request.setRequestHeader('Accept', 'application/json');
        request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        request.setRequestHeader('X-XSRF-TOKEN', xsrf());
        request.addEventListener('load', () => {
            if (request.status === 201) return resolve(JSON.parse(request.responseText));
            let error = null;
            try {
                error = JSON.parse(request.responseText).error;
            } catch {}
            const fields = error?.details?.fields ?? {};
            reject(new Error({ 413: `${file.name} is bigger than this computer takes (${size(upload.maxBytes)}).`, 419: 'Your session has ended. Reload the page and try again.' }[request.status]
                ?? fields.file?.[0] ?? error?.message ?? `${file.name} couldn't be uploaded. Try it again.`));
        });
        request.addEventListener('error', () => reject(new Error('ViStud couldn\'t be reached. Check the connection and try again.')));
        request.send(form);
    });
}

export function tutorChat({ upload }) {
    return {
        draft: '',
        pending: '',
        pendingFiles: [],
        live: false,
        follow: true,
        attached: [],
        picking: false,
        search: '',
        uploading: 0,
        note: '',

        init() {
            this.stick();
            if (this.$refs.log) new MutationObserver(() => this.stick()).observe(this.$refs.log, { childList: true, subtree: true, characterData: true });
        },

        stick() {
            const log = this.$refs.log;
            if (log && this.follow) log.scrollTop = log.scrollHeight;
        },

        get busy() {
            return this.live || this.uploading > 0;
        },

        submit() {
            const words = this.draft.trim();
            if ((words === '' && this.attached.length === 0) || this.busy) return;
            const refs = this.attached.map((item) => item.ref);
            [this.pending, this.pendingFiles, this.draft, this.attached] = [words, this.attached, '', []];
            [this.live, this.follow, this.picking, this.note] = [true, true, false, ''];
            this.$wire.send(words, refs);
        },

        say(words) {
            if (this.busy) return;
            [this.pending, this.live, this.follow] = [words, true, true];
            this.$wire.say(words);
        },

        retry() {
            if (this.busy) return;
            [this.live, this.follow] = [true, true];
            this.$wire.retry();
        },

        /** The turn is over. A refused one gives its words and attachments back to the box. */
        done(restore) {
            if (restore !== null && restore !== undefined) {
                this.draft = restore;
                this.attached = this.pendingFiles;
            }
            [this.pending, this.pendingFiles, this.live] = ['', [], false];
            this.$nextTick(() => {
                this.stick();
                this.$refs.box?.focus();
            });
        },

        has(ref) {
            return this.attached.some((item) => item.ref === ref);
        },

        toggle(item) {
            if (this.has(item.ref)) return this.remove(item.ref);
            if (this.attached.length >= MOST) return (this.note = `Attach up to ${MOST} at a time.`);
            this.attached.push(item);
            this.note = '';
        },

        remove(ref) {
            this.attached = this.attached.filter((item) => item.ref !== ref);
            this.note = '';
        },

        matches(name) {
            return name.toLowerCase().includes(this.search.trim().toLowerCase());
        },

        icon(kind) {
            return upload.icons[kind] ?? upload.icons.file;
        },

        /** Files chosen, pasted or dropped: each uploaded into the module, then attached. */
        async add(files) {
            for (const file of files) {
                if (this.attached.length + this.uploading >= MOST) {
                    this.note = `Attach up to ${MOST} at a time.`;
                    break;
                }
                if (file.size > upload.maxBytes) {
                    this.note = `${file.name} is bigger than ${size(upload.maxBytes)}.`;
                    continue;
                }
                this.uploading++;
                try {
                    const made = await uploadOne(upload, file);
                    this.attached.push({ ref: `file:${made.id}`, name: made.name, kind: made.kind === 'image' ? 'picture' : 'file' });
                    this.note = '';
                } catch (error) {
                    this.note = error.message;
                } finally {
                    this.uploading--;
                }
            }
            this.picking = false;
            this.$refs.box?.focus();
        },

        chosen(event) {
            const files = [...event.target.files];
            event.target.value = '';
            this.add(files);
        },

        /** A screenshot pasted into the box is uploaded and attached; pasted words stay words. */
        paste(event) {
            const pictures = [...(event.clipboardData?.files ?? [])].filter((file) => file.type.startsWith('image/'));
            if (pictures.length === 0) return;
            event.preventDefault();
            const stamp = new Date().toISOString().slice(0, 16).replace('T', ' ').replace(':', '.');
            this.add(pictures.map((file, i) => new File([file], `Pasted picture ${stamp}${pictures.length > 1 ? ` (${i + 1})` : ''}.${(file.type.split('/')[1] || 'png').replace('jpeg', 'jpg')}`, { type: file.type })));
        },

        drop(event) {
            const files = [...(event.dataTransfer?.files ?? [])];
            if (files.length > 0) this.add(files);
        },
    };
}

const register = () => window.Alpine?.data('tutorChat', tutorChat);
if (window.Alpine) register();
else document.addEventListener('alpine:init', register);
