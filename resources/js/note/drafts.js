/*
| Unsaved note drafts in the browser (ADR 0003 §5.3). Each account has its own
| IndexedDB database, vistud-drafts-{accountId}, so one account never sees
| another's drafts. A draft is keyed by note and tab (client_id). Drafts older
| than 30 days are removed when the editor opens.
*/

const STORE = 'drafts';
const EXPIRY_MS = 30 * 24 * 60 * 60 * 1000;

const request = (req) => new Promise((resolve, reject) => {
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
});

/** Opens the account's draft store. Rejects when the browser won't store anything (private mode, blocked storage). */
export async function openDrafts(accountId) {
    const open = indexedDB.open(`vistud-drafts-${accountId}`, 1);
    open.onupgradeneeded = () => {
        const store = open.result.createObjectStore(STORE, { keyPath: ['note_id', 'client_id'] });
        store.createIndex('note', 'note_id');
    };
    const db = await request(open);
    const tx = (mode) => db.transaction(STORE, mode).objectStore(STORE);

    return {
        /** Every draft of one note, newest first. */
        async forNote(noteId) {
            const drafts = await request(tx('readonly').index('note').getAll(noteId));
            return drafts.sort((a, b) => b.updated_at - a.updated_at);
        },
        put: (draft) => request(tx('readwrite').put({ ...draft, updated_at: Date.now() })),
        remove: (noteId, clientId) => request(tx('readwrite').delete([noteId, clientId])),
        /** Removes drafts nobody has touched for 30 days. */
        async expire() {
            const cutoff = Date.now() - EXPIRY_MS;
            const all = await request(tx('readonly').getAll());
            await Promise.all(all.filter((d) => d.updated_at < cutoff).map((d) => request(tx('readwrite').delete([d.note_id, d.client_id]))));
        },
    };
}
