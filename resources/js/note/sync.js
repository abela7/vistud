/*
| Keeping this device's drafts honest (ADR 0003 §5.4): before any draft is
| sent, the account's deletion records are read, and the drafts of deleted
| notes are removed unsent. Drafts of notes in the trash are kept: restoring
| the note brings them back. Also sends one draft on its own, for logging out.
*/

import { randomId } from './autosave.js';

const xsrf = () => decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.slice(11) ?? '');
const cursorKey = (accountId) => `vistud.tombstones.${accountId}`;

/** Removes drafts of deleted notes. Returns how many notes lost their draft. */
export async function purgeGone(accountId, drafts) {
    let since = 0;
    try {
        since = Number(localStorage.getItem(cursorKey(accountId)) ?? 0) || 0;
    } catch {}

    const deleted = new Set();
    for (let page = 0; page < 20; page++) {
        const response = await fetch(`/api/v1/sync/tombstones?since=${since}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!response.ok || response.headers.get('X-Account-Id') !== accountId) return 0;
        const body = await response.json();
        for (const record of body.data) {
            if (record.kind === 'deleted' || record.kind === 'redacted') deleted.add(record.entity_id);
            if (record.kind === 'restored') deleted.delete(record.entity_id);
        }
        since = body.next_since;
        if (!body.more) break;
    }

    const held = new Set((await drafts.all()).map((d) => d.note_id));
    const purged = [...deleted].filter((id) => held.has(id));
    await Promise.all(purged.map((id) => drafts.removeNote(id)));
    try {
        localStorage.setItem(cursorKey(accountId), String(since));
    } catch {}

    return purged.length;
}

/**
 * Sends one draft as a save on top of the version it was based on.
 * Returns saved, conflict, trashed, gone or failed.
 */
export async function sendDraft(draft) {
    try {
        const response = await fetch(`/api/v1/notes/${encodeURIComponent(draft.note_id)}`, {
            method: 'PUT',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf() },
            body: JSON.stringify({ base_version: draft.base_version, save_id: randomId(), client_id: draft.client_id, title: draft.title ?? '', doc: draft.doc }),
        });
        return { 200: 'saved', 409: 'conflict', 410: 'trashed', 404: 'gone' }[response.status] ?? 'failed';
    } catch {
        return 'failed';
    }
}
