/*
| The note's save queue (ADR 0003 §5.3). One save in flight at a time; a save
| starts 1.5 s after typing stops, or every 10 s during continuous typing.
| The draft goes to the browser's storage before every save, and only the
| revision the server confirmed is cleared. A failed save is retried with the
| same save_id (so a save that did reach the server isn't made twice), at 2, 5,
| 15, 30 and then every 60 s, and waits while the browser is offline. A
| conflict, the trash, an ended session or removed access stop the queue.
*/

const QUIET_MS = 1500;
const MAX_WAIT_MS = 10_000;
const DRAFT_MS = 250;
const RETRY_S = [2, 5, 15, 30, 60];

/** A random ID of 2 × bytes hex digits. Works on plain http too, unlike crypto.randomUUID. */
export const randomId = (bytes = 16) => Array.from(crypto.getRandomValues(new Uint8Array(bytes)), (b) => b.toString(16).padStart(2, '0')).join('');

const xsrf = () => decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.slice(11) ?? '');

/**
 * @param {object} o
 * @param {string} o.url         PUT /api/v1/notes/{id}
 * @param {string} o.noteId
 * @param {string} o.clientId    this tab
 * @param {string} o.accountId   the account the page was served for
 * @param {number} o.version     the version the editor shows
 * @param {object|null} o.drafts the draft store, or null when the browser won't store anything
 * @param {() => {title: string, doc: object}} o.snapshot
 * @param {(state: object) => void} o.onState
 */
export function createAutosave(o) {
    let base = o.version;
    let rev = 0;          // local changes so far
    let storedRev = 0;    // the newest change in the browser's storage
    let savedRev = 0;     // the newest change the server has
    let firstUnsaved = null;
    let pending = null;   // { rev, body }: the save being sent, kept for retries
    let busy = false;
    let halted = null;    // conflict · gone · session · blocked · rejected · account
    let storageOk = o.drafts !== null;
    let kind = 'autosave';
    let conflictVersion = null;
    let message = null;
    let attempt = 0;
    let retryAt = null;
    let saveTimer = null;
    let draftTimer = null;
    let retryTimer = null;
    let ticker = null;

    const status = () => {
        if (halted) return halted;
        if (busy && pending) return 'saving';
        if (retryAt) return 'retrying';
        if (rev === savedRev && !pending) return 'saved';
        if (!storageOk) return 'nostorage';
        if (!navigator.onLine) return 'offline';
        return 'local';
    };

    const emit = () => o.onState({
        status: status(),
        retryIn: retryAt ? Math.max(1, Math.ceil((retryAt - Date.now()) / 1000)) : null,
        conflictVersion,
        message,
    });

    async function storeDraft() {
        clearTimeout(draftTimer);
        draftTimer = null;
        if (!o.drafts || rev === storedRev) return;
        const at = rev;
        const draft = { note_id: o.noteId, client_id: o.clientId, base_version: base, draft_rev: at, ...o.snapshot() };
        try {
            await o.drafts.put(draft);
            storedRev = Math.max(storedRev, at);
            storageOk = true;
        } catch {
            storageOk = false;
        }
        emit();
    }

    function schedule(delay = null) {
        clearTimeout(saveTimer);
        if (halted || retryAt) return;
        const wait = delay ?? Math.max(0, Math.min(QUIET_MS, MAX_WAIT_MS - (Date.now() - (firstUnsaved ?? Date.now()))));
        saveTimer = setTimeout(save, wait);
    }

    function halt(reason) {
        halted = reason;
        clearTimeout(saveTimer);
        clearTimeout(retryTimer);
        clearInterval(ticker);
        retryAt = null;
        emit();
    }

    function retry() {
        const delay = RETRY_S[Math.min(attempt, RETRY_S.length - 1)] * 1000;
        attempt++;
        retryAt = Date.now() + delay;
        clearInterval(ticker);
        ticker = setInterval(emit, 1000);
        retryTimer = setTimeout(() => {
            retryAt = null;
            clearInterval(ticker);
            save();
        }, delay);
        emit();
    }

    async function save() {
        clearTimeout(saveTimer);
        if (halted || busy || retryAt) return;
        if (!pending && rev === savedRev) return;
        if (!navigator.onLine) {
            emit();
            return;
        }

        busy = true;
        try {
            if (!pending) {
                await storeDraft();
                pending = { rev, body: { base_version: base, save_id: randomId(), client_id: o.clientId, kind, ...o.snapshot() } };
            }
            emit();

            let response = null;
            let data = null;
            try {
                response = await fetch(o.url, {
                    method: 'PUT',
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf() },
                    body: JSON.stringify(pending.body),
                });
                data = await response.json().catch(() => null);
            } catch {
                response = null;
            }

            const account = response?.headers.get('X-Account-Id');
            if (account && account !== o.accountId) {
                // Another account signed in in this browser: stop at once.
                pending = null;
                halt('account');
                return;
            }

            if (response?.ok) {
                const sent = pending;
                pending = null;
                attempt = 0;
                kind = 'autosave';
                base = data.version;
                savedRev = Math.max(savedRev, sent.rev);
                if (savedRev === rev) {
                    firstUnsaved = null;
                    // Only the saved revision is cleared: nothing newer exists.
                    if (o.drafts && storageOk) await o.drafts.remove(o.noteId, o.clientId).catch(() => {});
                    storedRev = rev;
                } else {
                    // Newer typing sits on top of what was saved: re-store it on the new base.
                    storedRev = savedRev;
                    await storeDraft();
                }
                o.onSaved?.(data);
                return;
            }

            const code = response?.status;
            const error = data?.error;
            if (code === 409) {
                conflictVersion = error?.details?.current_version ?? null;
                pending = null;
                halt('conflict');
            } else if (code === 410 || code === 404) {
                pending = null;
                halt('gone');
            } else if (code === 401 || code === 419) {
                pending = null;
                halt('session');
            } else if (code === 403) {
                pending = null;
                halt('blocked');
            } else if (code === 422 || code === 413) {
                pending = null;
                message = Object.values(error?.details?.fields ?? {})[0]?.[0] ?? 'This note can\'t be saved as it is.';
                halt('rejected');
            } else {
                retry();
            }
        } finally {
            busy = false;
            emit();
            if (!halted && !retryAt && (pending || rev > savedRev)) schedule();
        }
    }

    const online = () => save();
    const offline = () => emit();
    window.addEventListener('online', online);
    window.addEventListener('offline', offline);

    return {
        /** The editor or title changed. */
        changed() {
            rev++;
            firstUnsaved ??= Date.now();
            draftTimer ??= setTimeout(storeDraft, DRAFT_MS);
            schedule();
            emit();
        },
        /** Store and send now (the tab is being hidden). */
        flush() {
            storeDraft();
            save();
        },
        /** Changes exist only in memory: closing the tab would lose them. */
        atRisk: () => rev > storedRev || (!storageOk && rev > savedRev),
        /** A draft restored from the browser's storage, based on version `from`: store it as this tab's and send it. */
        restored(from) {
            base = from;
            rev++;
            firstUnsaved = Date.now();
            storeDraft().then(() => schedule(0));
        },
        /** After a conflict: my version is saved on top of theirs. */
        keepMine(current) {
            base = current ?? conflictVersion ?? base;
            kind = 'conflict_resolution';
            conflictVersion = null;
            halted = null;
            rev++;
            save();
        },
        /** After a conflict: the editor now shows version `version` from the server, and my draft is dropped. */
        keepTheirs(version) {
            base = version;
            conflictVersion = null;
            halted = null;
            rev = storedRev = savedRev = 0;
            pending = null;
            o.drafts?.remove(o.noteId, o.clientId).catch(() => {});
            emit();
        },
        /** A conflict found on opening: the draft was based on an older version. */
        conflict(current) {
            conflictVersion = current;
            halt('conflict');
        },
        emit,
    };
}
