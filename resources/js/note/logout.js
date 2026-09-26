/*
| Logging out with drafts that aren't saved yet (ADR 0003 §5.3). The student
| chooses: save them now, keep them on this device (for the next time this
| account logs in here), or discard them. With no drafts, logging out just
| happens.
*/

import { deleteDrafts, hasDrafts, openDrafts } from './drafts.js';
import { purgeGone, sendDraft } from './sync.js';

const plural = (n, word) => `${n} ${word}${n === 1 ? '' : 's'}`;

function leave(form, accountId) {
    try {
        new BroadcastChannel(`vistud-${accountId}`).postMessage({ type: 'logout' });
    } catch {}
    form.setAttribute('aria-busy', 'true');
    form.querySelector('button[type="submit"]')?.setAttribute('aria-busy', 'true');
    form.submit();
}

export async function beforeLogout(form, accountId) {
    // An open note stores what it holds first.
    const waits = [];
    window.dispatchEvent(new CustomEvent('vistud:store-drafts', { detail: { waitFor: (promise) => waits.push(promise) } }));
    await Promise.allSettled(waits);

    let drafts = null;
    try {
        if (await hasDrafts(accountId)) drafts = await openDrafts(accountId);
    } catch {}
    if (drafts) await purgeGone(accountId, drafts).catch(() => 0);
    const all = drafts ? await drafts.all().catch(() => []) : [];
    if (all.length === 0) {
        drafts?.close();
        return leave(form, accountId);
    }

    const dialog = document.querySelector('[data-logout-dialog]');
    const notes = new Set(all.map((d) => d.note_id)).size;
    const problem = dialog.querySelector('[data-logout-problem]');
    dialog.querySelector('[data-logout-summary]').textContent = `${plural(notes, 'note')} on this device ${notes === 1 ? 'has' : 'have'} changes that aren't saved yet.`;
    problem.hidden = true;
    dialog.showModal();

    dialog.onclick = async (event) => {
        const choice = event.target.closest('[data-logout-choice]')?.dataset.logoutChoice;
        if (!choice) return;
        if (choice === 'keep') return leave(form, accountId);
        if (choice === 'discard') {
            drafts.close();
            await deleteDrafts(accountId).catch(() => {});
            return leave(form, accountId);
        }

        // Save them now: each draft on top of the version it was based on.
        const buttons = [...dialog.querySelectorAll('[data-logout-choice]')];
        buttons.forEach((b) => { b.disabled = true; });
        let left = 0;
        for (const draft of await drafts.all()) {
            const result = await sendDraft(draft);
            if (result === 'saved' || result === 'gone') await drafts.remove(draft.note_id, draft.client_id);
            else left++;
        }
        buttons.forEach((b) => { b.disabled = false; });
        if (left === 0) return leave(form, accountId);

        problem.querySelector('[data-logout-problem-text]').textContent = `${plural(left, 'change')} couldn't be saved: the note was changed somewhere else, is in the trash, or the connection failed. Keep ${left === 1 ? 'it' : 'them'} on this device and open the note next time, or discard ${left === 1 ? 'it' : 'them'}.`;
        problem.hidden = false;
    };
}
