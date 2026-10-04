import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { foreignColours, makeStudentWithNote, openStudentHome, openTab, THEMES, useSentinelTheme, useTheme } from './support.js';

/* Notes and the editor (docs/specs/workspaces.md step 3, ADR 0003 §5 and §14 M2 checks 11–14). */

const desktop = { width: 1440, height: 900 };
const phone = { width: 390, height: 844 };
const status = (page) => page.locator('[data-save-status]');
const body = (page) => page.locator('.note-prose');
const alert = (page, name) => page.locator(`[data-alert="${name}"]`);
const apiUrl = (note) => note.url.replace(/^\/workspaces\/[^/]+\/notes\//, '/api/v1/notes/');
const serverText = async (page, note) => JSON.stringify((await (await page.request.get(apiUrl(note))).json()).doc);

test.use({ reducedMotion: 'reduce' });
test.describe.configure({ timeout: 60_000 });

async function openNote(page, which = 'url') {
    const note = makeStudentWithNote();
    await openStudentHome(page, note.email);
    await page.goto(note[which]);
    await page.locator('[data-note-editor][data-ready]').waitFor();
    return note;
}

/** A long note, written through the API: 12 paragraphs, a page break the writer made, then 26 more (six pages). */
async function writeLongNote(page, note) {
    const api = apiUrl(note);
    const current = await (await page.request.get(api)).json();
    const xsrf = decodeURIComponent((await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN').value);
    const text = (words) => ({ type: 'text', text: words });
    const paragraph = (n) => ({ type: 'paragraph', content: [text(`Paragraph ${n}. ${'Cells copy their DNA before they divide, so each new cell gets a full set of instructions. '.repeat(3)}`)] });
    const doc = { type: 'doc', content: [
        { type: 'heading', attrs: { level: 2 }, content: [text('Cell division')] },
        ...Array.from({ length: 12 }, (_, i) => paragraph(i + 1)),
        { type: 'pageBreak' },
        { type: 'heading', attrs: { level: 2 }, content: [text('After the break')] },
        ...Array.from({ length: 26 }, (_, i) => paragraph(i + 20)),
    ] };
    const saved = await page.request.put(api, {
        headers: { 'X-XSRF-TOKEN': xsrf, 'Content-Type': 'application/json', Accept: 'application/json' },
        data: { base_version: current.version, save_id: 'long-0001', client_id: 'long-0001', title: 'Cell division notes', doc },
    });
    expect(saved.ok()).toBe(true);
}

async function typeAtEnd(page, text) {
    await body(page).click();
    await page.keyboard.press('Control+End');
    await page.keyboard.type(text);
}

test('a student writes a note: it saves by itself, and the title names the page', async ({ page }) => {
    await page.setViewportSize(desktop);
    const note = await openNote(page, 'empty');
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Untitled note');
    await expect(page.getByRole('navigation', { name: 'Where this note is' })).toHaveText(/Modules\s*Week 1: Cells\s*Labs/);

    await page.getByLabel('Title').fill('Lab 1: what I saw');
    await page.keyboard.press('Enter');
    await expect(body(page)).toBeFocused();
    await page.keyboard.type('Onion cells look like bricks.');
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Lab 1: what I saw');
    await expect(page).toHaveTitle(/^Lab 1: what I saw · Biology/);
    await expect(status(page)).toHaveText('Saved');

    await page.reload();
    await page.locator('[data-note-editor][data-ready]').waitFor();
    await expect(page.getByLabel('Title')).toHaveValue('Lab 1: what I saw');
    await expect(body(page)).toHaveText('Onion cells look like bricks.');
    expect(await serverText(page, { url: note.empty })).toContain('Onion cells look like bricks.');
});

test('formatting from the toolbar and the keyboard', async ({ page }) => {
    await page.setViewportSize(desktop);
    await openNote(page, 'empty');
    const tool = (name) => page.getByRole('toolbar', { name: 'Formatting' }).getByRole('button', { name, exact: true });

    await body(page).click();
    await page.getByLabel('Text style').selectOption('2');
    await page.keyboard.type('Microscopes');
    await expect(body(page).locator('h2')).toHaveText('Microscopes');
    await expect(page.getByLabel('Text style')).toHaveValue('2');

    await page.keyboard.press('Enter');
    await page.keyboard.press('Control+b');
    await page.keyboard.type('Focus');
    await expect(body(page).locator('strong')).toHaveText('Focus');
    await expect(tool('Bold')).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByLabel('Text style')).toHaveValue('paragraph');

    await page.keyboard.press('Enter');
    await tool('Checklist').click();
    await page.keyboard.type('Draw what I see');
    await body(page).getByRole('checkbox').check();
    await expect(body(page).locator('li[data-checked="true"] > div')).toHaveText('Draw what I see');

    // One tab stop: the arrow keys move along the toolbar.
    await tool('Bold').focus();
    await page.keyboard.press('ArrowRight');
    await expect(tool('Italic')).toBeFocused();
    await page.keyboard.press('End');
    await expect(tool('Clear formatting')).toBeFocused();
    await page.keyboard.press('Home');
    await expect(tool('Undo')).toBeFocused();
    await expect(status(page)).toHaveText('Saved');
});

test('the richer toolbar: underline, highlight, alignment, scripts, a table and a link, all kept', async ({ page }) => {
    await page.setViewportSize(desktop);
    const note = await openNote(page, 'empty');
    const tool = (name) => page.getByRole('toolbar', { name: 'Formatting' }).getByRole('button', { name, exact: true });
    // A selection made with the keyboard reaches the editor a moment later; the next key waits for it, as a person would.
    const key = async (keys) => {
        await page.keyboard.press(keys);
        await page.evaluate(() => new Promise((done) => requestAnimationFrame(() => requestAnimationFrame(done))));
    };

    await body(page).click();
    await page.keyboard.type('Water is H');
    await tool('Subscript').click();
    await page.keyboard.type('2');
    await tool('Subscript').click();
    await page.keyboard.type('O, and x');
    await tool('Superscript').click();
    await page.keyboard.type('2');
    await tool('Superscript').click();
    await key('Shift+Home');
    await tool('Underline').click();
    await tool('Highlight').click();
    await page.getByRole('menuitemradio', { name: 'Green' }).click();
    await tool('Align').click();
    await page.getByRole('menuitemradio', { name: 'Centre' }).click();
    await expect(body(page).locator('p').first()).toHaveCSS('text-align', 'center');
    await expect(tool('Align').locator('[data-align-icon="center"]')).toBeVisible();

    await key('End');
    await key('Enter');
    await page.getByLabel('Text style').selectOption('paragraph');
    await tool('Align').click();
    await page.getByRole('menuitemradio', { name: 'Left' }).click();
    await page.keyboard.type('See the lab sheet');
    await key('Shift+Home');
    await tool('Link').click();
    await page.getByRole('textbox', { name: 'Link address' }).fill('example.org/lab');
    await page.getByRole('textbox', { name: 'Link address' }).press('Enter');
    await expect(body(page).locator('a')).toHaveAttribute('href', 'https://example.org/lab');

    await key('End');
    await key('Enter');
    await tool('Table').click();
    const tableBar = page.getByRole('toolbar', { name: 'Table' });
    await expect(tableBar).toBeVisible();
    await page.keyboard.type('Stage');
    await tableBar.getByRole('button', { name: 'Row below' }).click();
    await expect(body(page).locator('table tr')).toHaveCount(4);
    await expect(page.locator('[data-note-count]')).toContainText('words');
    await expect(status(page)).toHaveText('Saved');

    // All of it comes back as it was.
    await page.goto(note.empty);
    await page.locator('[data-note-editor][data-ready]').waitFor();
    const first = body(page).locator('p').first();
    await expect(first).toHaveCSS('text-align', 'center');
    await expect(first.locator('mark[data-tone="green"]').first()).toBeVisible();
    await expect(first.locator('sub')).toHaveText('2');
    await expect(first.locator('sup')).toHaveText('2');
    await expect(first.locator('u').first()).toBeVisible();
    await expect(body(page).locator('a')).toHaveAttribute('href', 'https://example.org/lab');
    await expect(body(page).locator('table th').first()).toHaveText('Stage');
    await expect(body(page).locator('table tr')).toHaveCount(4);
});

test('read mode and full screen, for reading and for writing', async ({ page }) => {
    await page.setViewportSize(desktop);
    await openNote(page);
    const toolbar = page.getByRole('toolbar', { name: 'Formatting' });

    await page.getByRole('button', { name: 'Read', exact: true }).click();
    await expect(toolbar).toBeHidden();
    await expect(body(page)).toHaveAttribute('contenteditable', 'false');
    await expect(page.getByLabel('Title')).toHaveJSProperty('readOnly', true);
    await page.getByRole('button', { name: 'Edit', exact: true }).click();
    await expect(body(page)).toBeFocused();
    await expect(toolbar).toBeVisible();

    await page.getByRole('button', { name: 'Full screen' }).click();
    const box = await page.locator('[data-note-page]').boundingBox();
    expect([box.x, box.y, box.width]).toEqual([0, 0, desktop.width]);
    // The note covers the top bar.
    expect(await page.evaluate(() => document.elementFromPoint(20, 20).closest('[data-note-page]') !== null)).toBe(true);
    await typeAtEnd(page, ' Written in full screen.');
    await expect(status(page)).toHaveText('Saved');
    await page.getByRole('button', { name: 'Exit full screen' }).click();
    await expect(page.locator('[data-note-page]')).not.toHaveAttribute('data-focus');
    await expect(page.getByRole('button', { name: 'Full screen' })).toBeFocused();
});

test('full screen shows only the note: no header, and what still matters is in the status bar', async ({ page }) => {
    await page.setViewportSize(desktop);
    await openNote(page);
    const toolbar = page.getByRole('toolbar', { name: 'Formatting' });
    const bar = page.locator('.note-count-bar');
    await page.getByRole('button', { name: 'Full screen' }).click();

    // No Back link, no path, no header buttons: the toolbar is the first thing on the screen.
    await expect(page.locator('.note-header-bar')).toBeHidden();
    await expect(page.locator('[data-back]')).toBeHidden();
    await expect(page.getByRole('navigation', { name: 'Where this note is' })).toBeHidden();
    expect((await toolbar.boundingBox()).y).toBeLessThanOrEqual(2);
    // What still matters is in the status bar: the save status, the view, Read, Print and Exit.
    await expect(bar.locator('[data-save-status]')).toBeVisible();
    await expect(bar.locator('[data-save-status]')).toHaveText('Saved');
    for (const name of ['Toggle document view mode', 'Read', 'Print or export PDF', 'Exit full screen']) {
        await expect(bar.getByRole('button', { name, exact: true })).toBeVisible();
    }
    // Writing is saved and shown there; Read hides the toolbar but the way out stays.
    await typeAtEnd(page, ' Written in full screen.');
    await expect(bar.locator('[data-save-status]')).toHaveText('Saved');
    await bar.getByRole('button', { name: 'Read', exact: true }).click();
    await expect(toolbar).toBeHidden();
    await expect(bar.getByRole('button', { name: 'Exit full screen', exact: true })).toBeVisible();
    await bar.getByRole('button', { name: 'Edit', exact: true }).click();
    await expect(toolbar).toBeVisible();
    await bar.getByRole('button', { name: 'Toggle document view mode' }).click();
    await expect(page.locator('[data-note-page]')).toHaveAttribute('data-page-view', 'continuous');
    await bar.getByRole('button', { name: 'Toggle document view mode' }).click();

    // Out again: the header is back with the save status in it, and focus is on the button that opened it.
    await bar.getByRole('button', { name: 'Exit full screen', exact: true }).click();
    await expect(page.locator('.note-header-bar')).toBeVisible();
    await expect(page.locator('.note-header-bar [data-save-status]')).toBeVisible();
    await expect(bar.locator('[data-save-status]')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Full screen' })).toBeFocused();
});

test('full screen on a phone: the status bar fits, and nothing scrolls sideways', async ({ page }) => {
    await page.setViewportSize(phone);
    await openNote(page);
    await page.getByRole('button', { name: 'Full screen' }).click();
    const bar = page.locator('.note-count-bar');
    for (const name of ['Toggle document view mode', 'Read', 'Print or export PDF', 'Exit full screen']) {
        const box = await bar.getByRole('button', { name, exact: true }).boundingBox();
        expect(box.x).toBeGreaterThanOrEqual(0);
        expect(box.x + box.width).toBeLessThanOrEqual(phone.width);
    }
    // "Saved" is its icon alone here, and still says so to a screen reader.
    await expect(bar.locator('[data-save-status]')).toHaveText('Saved');
    expect((await bar.locator('[data-save-label]').boundingBox()).width).toBeLessThanOrEqual(1);
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(phone.width);
    await bar.getByRole('button', { name: 'Exit full screen', exact: true }).click();
    await expect(page.locator('.note-header-bar')).toBeVisible();
});

test('with the server unreachable the draft survives a reload, and saves once it is back', async ({ page }) => {
    await page.setViewportSize(desktop);
    const note = await openNote(page);
    await page.route('**/api/v1/notes/*', (route) => (route.request().method() === 'PUT' ? route.abort() : route.continue()));

    await typeAtEnd(page, ' Kept safe.');
    await expect(status(page)).toHaveText(/Not saved, retrying in \d+ s/);

    await page.reload();
    await page.locator('[data-note-editor][data-ready]').waitFor();
    await expect(alert(page, 'restored')).toBeVisible();
    await expect(body(page)).toContainText('Kept safe.');

    await page.unroute('**/api/v1/notes/*');
    await expect(status(page)).toHaveText('Saved', { timeout: 15_000 });
    expect(await serverText(page, note)).toContain('Kept safe.');
});

test('a failed save is retried with the same save ID, and only the saved revision is cleared', async ({ page }) => {
    await page.setViewportSize(desktop);
    const note = await openNote(page);
    const saves = [];
    let release;
    const held = new Promise((resolve) => { release = resolve; });
    await page.route('**/api/v1/notes/*', async (route) => {
        if (route.request().method() !== 'PUT') return route.continue();
        saves.push(route.request().postDataJSON());
        if (saves.length === 1) return route.fulfill({ status: 500, body: '{}' });
        if (saves.length === 2) await held;
        return route.continue();
    });

    await typeAtEnd(page, ' First.');
    await expect.poll(() => saves.length, { timeout: 10_000 }).toBe(2);
    expect(saves[1].save_id).toBe(saves[0].save_id);

    // Typing while that save is in flight: the newer text goes in the next save.
    await page.keyboard.type(' Second.');
    release();
    await expect.poll(() => saves.length, { timeout: 10_000 }).toBe(3);
    expect(saves[2].save_id).not.toBe(saves[0].save_id);
    await expect(status(page)).toHaveText('Saved');
    expect(await serverText(page, note)).toContain(' First. Second.');
});

test('another tab of the same account: an idle tab updates itself, a busy one is warned at once', async ({ page, context }) => {
    await page.setViewportSize(desktop);
    const note = await openNote(page);
    const other = await context.newPage();
    await other.setViewportSize(desktop);
    await other.goto(note.url);
    await other.locator('[data-note-editor][data-ready]').waitFor();

    await typeAtEnd(page, ' From the first tab.');
    await expect(status(page)).toHaveText('Saved');
    await expect(body(other)).toContainText('From the first tab.');
    await expect(status(other)).toHaveText('Saved');

    // The first tab is saving when the second one saves.
    let release;
    const held = new Promise((resolve) => { release = resolve; });
    await page.route('**/api/v1/notes/*', async (route) => {
        if (route.request().method() === 'PUT') await held;
        return route.continue();
    });
    await typeAtEnd(page, ' Mine.');
    await expect.poll(() => page.locator('[data-save-status]').getAttribute('data-state')).toBe('saving');
    await typeAtEnd(other, ' Theirs.');
    await expect(status(other)).toHaveText('Saved');
    await expect(alert(page, 'conflict')).toBeVisible();

    release();
    await page.getByRole('button', { name: 'Keep my version' }).click();
    await expect(status(page)).toHaveText('Saved');
    const saved = await serverText(page, note);
    expect(saved).toContain('Mine.');
    expect(saved).not.toContain('Theirs.');
    // The second tab had nothing unsaved, so it shows the version that was kept.
    await expect(body(other)).toContainText('Mine.');
});

test('a newer version from another device is a conflict, and the newer one can be used', async ({ page, context }) => {
    await page.setViewportSize(desktop);
    const note = await openNote(page);

    // Another device saves: straight through the API, so no tab of this browser hears of it.
    const current = await (await page.request.get(apiUrl(note))).json();
    const token = decodeURIComponent((await context.cookies()).find((c) => c.name === 'XSRF-TOKEN').value);
    const put = await page.request.put(apiUrl(note), {
        headers: { 'X-XSRF-TOKEN': token, Accept: 'application/json' },
        data: { base_version: current.version, save_id: 'phone-00001', client_id: 'phone-00001', title: current.title, doc: { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'From my phone.' }] }] } },
    });
    expect(put.status()).toBe(200);

    await typeAtEnd(page, ' From the laptop.');
    await expect(status(page)).toHaveText('Conflict, needs your choice');
    await page.getByRole('button', { name: 'Use the newer version' }).click();
    await expect(body(page)).toHaveText('From my phone.');
    await expect(status(page)).toHaveText('Saved');
});

test('offline, typing carries on and is sent when the connection is back', async ({ page, context }) => {
    await page.setViewportSize(desktop);
    const note = await openNote(page);

    await context.setOffline(true);
    await typeAtEnd(page, ' Written on the train.');
    await expect(alert(page, 'offline')).toBeVisible();
    await expect(status(page)).toHaveText('Saved on this device');

    await context.setOffline(false);
    await expect(status(page)).toHaveText('Saved', { timeout: 10_000 });
    expect(await serverText(page, note)).toContain('Written on the train.');
});

async function logOut(page) {
    await page.locator('.account-button').click();
    await page.getByRole('button', { name: 'Log out' }).click();
}

async function withUnsentChange(page, note, text) {
    await page.route('**/api/v1/notes/*', (route) => (route.request().method() === 'PUT' ? route.abort() : route.continue()));
    await typeAtEnd(page, text);
    await expect(status(page)).toHaveText(/Not saved, retrying in \d+ s/);
}

test('logging out with unsaved changes asks first, and saving them sends them', async ({ page }) => {
    await page.setViewportSize(desktop);
    const note = await openNote(page);
    await withUnsentChange(page, note, ' Not sent yet.');

    await logOut(page);
    const dialog = page.getByRole('dialog', { name: 'Unsaved changes on this device' });
    await expect(dialog).toContainText('1 note on this device has changes that aren\'t saved yet.');
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(page).toHaveURL(note.url);

    await page.unroute('**/api/v1/notes/*');
    await logOut(page);
    await dialog.getByRole('button', { name: 'Save them and log out' }).click();
    await page.waitForURL('**/login');
    await openStudentHome(page, note.email);
    expect(await serverText(page, note)).toContain('Not sent yet.');
});

test('drafts kept at logout come back for the same account; discarded ones do not', async ({ page }) => {
    await page.setViewportSize(desktop);
    const note = await openNote(page);
    await withUnsentChange(page, note, ' Kept for later.');
    await logOut(page);
    await page.getByRole('button', { name: 'Keep them here and log out' }).click();
    await page.waitForURL('**/login');

    await page.unroute('**/api/v1/notes/*');
    await openStudentHome(page, note.email);
    await page.goto(note.url);
    await page.locator('[data-note-editor][data-ready]').waitFor();
    await expect(alert(page, 'restored')).toBeVisible();
    await expect(status(page)).toHaveText('Saved');
    expect(await serverText(page, note)).toContain('Kept for later.');

    await withUnsentChange(page, note, ' Thrown away.');
    await logOut(page);
    await page.getByRole('button', { name: 'Discard them and log out' }).click();
    await page.waitForURL('**/login');
    await page.unroute('**/api/v1/notes/*');
    await openStudentHome(page, note.email);
    await page.goto(note.url);
    await page.locator('[data-note-editor][data-ready]').waitFor();
    await expect(alert(page, 'restored')).toBeHidden();
    await expect(body(page)).not.toContainText('Thrown away.');
});

test('a draft of a note deleted elsewhere is removed before it could be sent', async ({ page, context }) => {
    await page.setViewportSize(desktop);
    const note = await openNote(page);
    await withUnsentChange(page, note, ' Never to be sent.');

    // Another tab deletes the note for good.
    const other = await context.newPage();
    await other.setViewportSize(desktop);
    await other.goto(`/courses/${note.workspace}/modules`);
    await other.locator('main').getByRole('link', { name: 'Week 2: Cell division' }).click();
    await openTab(other, 'Notes');
    await other.getByRole('button', { name: 'Actions for Mitosis vs meiosis' }).click();
    await other.getByRole('button', { name: 'Move to trash' }).click();
    // Leaving before the trash is saved would cancel it.
    await expect(other.getByRole('status').filter({ hasText: 'is in the trash.' })).toBeVisible();
    await other.goto(`/courses/${note.workspace}/notes`);
    await other.getByRole('button', { name: 'Trash (1)' }).click();
    await other.getByRole('button', { name: 'Delete for good: Mitosis vs meiosis' }).click();
    await other.getByRole('dialog').getByRole('button', { name: 'Delete for good' }).click();
    await expect(other.getByRole('status').filter({ hasText: 'is deleted.' })).toBeVisible();

    await page.goto(`/courses/${note.workspace}/notes`);
    await expect(page.locator('[data-toasts]')).toContainText('deleted on another device were removed from this one');
    const left = await page.evaluate(async (account) => {
        const db = await new Promise((resolve) => { const r = indexedDB.open(`vistud-drafts-${account}`); r.onsuccess = () => resolve(r.result); });
        const all = await new Promise((resolve) => { const r = db.transaction('drafts').objectStore('drafts').getAll(); r.onsuccess = () => resolve(r.result); });
        return all.length;
    }, await page.locator('meta[name="vistud-account"]').getAttribute('content'));
    expect(left).toBe(0);
});

test('an account that no longer exists leaves nothing on the device', async ({ page }) => {
    await page.setViewportSize(desktop);
    await openNote(page);
    await page.route('**/api/v1/notes/*', (route) => route.fulfill({ status: 401, contentType: 'application/json', body: '{"error":{"code":"account_deleted","message":"x"}}' }));
    await typeAtEnd(page, ' Gone with the account.');
    await expect(alert(page, 'deleted')).toBeVisible();
    await expect.poll(() => page.evaluate(async () => (await indexedDB.databases()).filter((db) => db.name.startsWith('vistud-drafts-')).length)).toBe(0);
});

test('an ended session and a browser that stores nothing are both said plainly', async ({ page, context }) => {
    await page.setViewportSize(desktop);
    await page.addInitScript(() => {
        Object.defineProperty(window, 'indexedDB', { get() { throw new Error('storage blocked'); } });
    });
    await openNote(page);
    let release;
    const held = new Promise((resolve) => { release = resolve; });
    await page.route('**/api/v1/notes/*', async (route) => {
        await held;
        return route.continue();
    });

    await typeAtEnd(page, ' Only in memory.');
    await expect(status(page)).toHaveText('Not stored on this device, keep this tab open');
    await expect(alert(page, 'nostorage')).toBeVisible();

    await context.clearCookies();
    release();
    await expect(alert(page, 'session')).toBeVisible();
    await expect(status(page)).toHaveText('Not saved');
});

test('the note page and another student\'s note', async ({ page, browser }) => {
    await page.setViewportSize(desktop);
    const note = await openNote(page);

    const intruder = await browser.newPage();
    await openStudentHome(intruder, makeStudentWithNote().email);
    const response = await intruder.goto(note.url);
    expect(response.status()).toBe(404);
    expect((await intruder.request.get(apiUrl(note))).status()).toBe(404);
});

test('moving a note to the trash from its page', async ({ page }) => {
    await page.setViewportSize(desktop);
    await openNote(page);
    await page.getByRole('button', { name: 'Actions for Mitosis vs meiosis' }).click();
    await page.getByRole('button', { name: 'Move to trash' }).click();
    await expect(page.getByText('“Mitosis vs meiosis” is in the trash.')).toBeVisible();
});

for (const [name, viewport] of Object.entries({ desktop, phone })) {
    test(`every colour on a note comes from a token: ${name}`, async ({ page }) => {
        await page.setViewportSize(viewport);
        await openNote(page);
        await useSentinelTheme(page);
        await body(page).locator('strong').first().click();
        const states = { 'note, bold pressed': await foreignColours(page) };

        await page.route('**/api/v1/notes/*', (route) => route.fulfill({ status: 409, contentType: 'application/json', body: '{"error":{"code":"version_conflict","message":"x","details":{"current_version":9}}}' }));
        await typeAtEnd(page, ' x');
        await alert(page, 'conflict').waitFor();
        states['conflict'] = await foreignColours(page);

        for (const [state, colours] of Object.entries(states)) {
            expect(colours, `${state}: colours not from a token`).toEqual([]);
        }
    });
}

for (const theme of THEMES) {
    test(`axe finds no violations on a note: ${theme}`, async ({ page }) => {
        await page.setViewportSize(desktop);
        await openNote(page);
        await useTheme(page, theme);
        const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
        expect(results.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target).join(' ')}`)).toEqual([]);
    });
}

test('a note never scrolls sideways at 320 px, even with 200% text', async ({ page }) => {
    await openNote(page);
    await page.setViewportSize({ width: 320, height: 800 });
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
});

async function openNotesAndFiles(page) {
    const note = makeStudentWithNote();
    await openStudentHome(page, note.email);
    await page.goto(`/courses/${note.workspace}/notes`);
    await page.getByRole('heading', { level: 1, name: 'Notes & files' }).waitFor();
    await page.waitForLoadState('load');
    return note;
}

test('Notes & files: a new note, and the trash', async ({ page }) => {
    await page.setViewportSize(desktop);
    await openNotesAndFiles(page);

    await page.locator('main').getByRole('button', { name: 'New', exact: true }).click();
    await page.locator('#new-menu').getByRole('button', { name: 'Note' }).click();
    await page.locator('[data-note-editor][data-ready]').waitFor();
    await page.getByLabel('Title').fill('Exam plan');
    await expect(status(page)).toHaveText('Saved');
    await page.getByRole('navigation', { name: 'Where this note is' }).getByRole('link', { name: 'Notes & files' }).click();

    const loose = page.getByRole('list', { name: 'Notes, files and links' });
    await expect(loose.getByRole('link', { name: 'Exam plan' })).toBeVisible();
    await loose.getByRole('button', { name: 'Actions for Exam plan' }).click();
    await loose.getByRole('button', { name: 'Move to trash' }).click();
    await expect(page.getByRole('status').filter({ hasText: '“Exam plan” is in the trash.' })).toBeVisible();

    await page.getByRole('button', { name: 'Trash (1)' }).click();
    await page.getByRole('button', { name: 'Restore Exam plan' }).click();
    await expect(loose.getByRole('link', { name: 'Exam plan' })).toBeVisible();
});

test('a new note is kept only once something is written in it', async ({ page }) => {
    await page.setViewportSize(desktop);
    const note = await openNotesAndFiles(page);
    const loose = page.getByRole('list', { name: 'Notes, files and links' });
    const before = await loose.getByRole('listitem').count();

    // Opened and left empty: nothing is kept.
    await page.locator('main').getByRole('button', { name: 'New', exact: true }).click();
    await page.locator('#new-menu').getByRole('button', { name: 'Note' }).click();
    await page.locator('[data-note-editor][data-ready]').waitFor();
    expect(page.url()).toMatch(/\/notes\/new$/);
    await expect(status(page)).toHaveText('Not saved yet');
    await page.goto(`/courses/${note.workspace}/notes`);
    await expect(loose.getByRole('listitem')).toHaveCount(before);

    // The first words make it, and the address becomes the note's.
    await page.locator('main').getByRole('button', { name: 'New', exact: true }).click();
    await page.locator('#new-menu').getByRole('button', { name: 'Note' }).click();
    await page.locator('[data-note-editor][data-ready]').waitFor();
    await page.locator('.note-prose').click();
    await page.keyboard.type('Revise osmosis before Friday.');
    await expect(status(page)).toHaveText('Saved');
    await expect(page).toHaveURL(/\/notes\/[0-9a-f-]{36}$/);
    await page.keyboard.type(' And diffusion.');
    await expect(status(page)).toHaveText('Saved');
    await page.reload();
    await page.locator('[data-note-editor][data-ready]').waitFor();
    await expect(page.locator('.note-prose')).toHaveText('Revise osmosis before Friday. And diffusion.');
    await page.goto(`/courses/${note.workspace}/notes`);
    await expect(loose.getByRole('listitem')).toHaveCount(before + 1);
});

for (const [name, viewport] of Object.entries({ desktop, phone })) {
    test(`every colour in Notes & files comes from a token: ${name}`, async ({ page }) => {
        await page.setViewportSize(viewport);
        const note = await openNotesAndFiles(page);
        await useSentinelTheme(page);
        await page.getByRole('button', { name: 'Trash (0)' }).click();
        await page.getByText('The trash is empty.').waitFor();
        expect(await foreignColours(page), 'colours not from a token').toEqual([]);
        expect(note.url).toBeTruthy();
    });
}

for (const theme of THEMES) {
    test(`axe finds no violations in Notes & files: ${theme}`, async ({ page }) => {
        await page.setViewportSize(desktop);
        await openNotesAndFiles(page);
        await useTheme(page, theme);
        await page.getByRole('button', { name: 'Trash (0)' }).click();
        await page.getByText('The trash is empty.').waitFor();
        const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
        expect(results.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target).join(' ')}`)).toEqual([]);
    });
}

test('the toolbar menus and the table bar: colours from tokens, and axe finds nothing', async ({ page }) => {
    await page.setViewportSize(desktop);
    await openNote(page, 'empty');
    const tool = (name) => page.getByRole('toolbar', { name: 'Formatting' }).getByRole('button', { name, exact: true });
    await body(page).click();
    await tool('Table').click();
    await page.getByRole('toolbar', { name: 'Table' }).waitFor();
    await tool('Highlight').click();
    await page.getByRole('menu', { name: 'Highlight' }).waitFor();
    const violations = (await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze()).violations.map((v) => v.id);
    expect(violations).toEqual([]);
    await useSentinelTheme(page);
    expect(await foreignColours(page)).toEqual([]);
});

test('Word shortcuts act once, a picture is kept with the note, and the side panels pass axe', async ({ page }) => {
    await page.setViewportSize(desktop);
    const note = await openNote(page, 'empty');
    const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64');
    const key = async (keys) => {
        await page.keyboard.press(keys);
        await page.evaluate(() => new Promise((done) => requestAnimationFrame(() => requestAnimationFrame(done))));
    };
    const violations = async () => (await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze()).violations.map((v) => v.id);

    // Ctrl+E centres (Tiptap alone would make code); a list shortcut toggles once, not on and off again.
    await body(page).click();
    await page.keyboard.type('Cells');
    await key('Control+e');
    await expect(body(page).locator('p').first()).toHaveCSS('text-align', 'center');
    await expect(body(page).locator('code')).toHaveCount(0);
    await key('End');
    await key('Enter');
    await key('Control+Shift+8');
    await page.keyboard.type('Nucleus');
    await expect(body(page).locator('ul > li')).toHaveText(['Nucleus']);
    await key('Enter');
    await key('Enter');
    await key('Control+Enter');
    await expect(body(page).locator('[data-page-break]')).toHaveCount(1);

    // A picture from this device: kept for the note, and shown from its own address.
    await key('Control+Shift+i');
    const panel = page.getByRole('dialog', { name: 'Add a picture' });
    await expect(panel).toBeVisible();
    expect(await violations()).toEqual([]);
    const chooser = page.waitForEvent('filechooser');
    await panel.getByRole('button', { name: 'Choose a picture' }).click();
    await (await chooser).setFiles({ name: 'cell.png', mimeType: 'image/png', buffer: png });
    await expect(panel).toBeHidden();
    const picture = body(page).locator('figure[data-note-image] img');
    await expect(picture).toHaveAttribute('src', /\/notes\/images\/[0-9a-f-]+$/);
    await expect.poll(() => picture.evaluate((img) => img.naturalWidth)).toBe(1);
    // The writing goes on after the picture and the break; neither is typed over.
    await page.keyboard.type('Seen at 400x');
    await expect(body(page).locator('p').last()).toHaveText('Seen at 400x');
    await expect(status(page)).toHaveText('Saved');

    await page.goto(note.empty);
    await page.locator('[data-note-editor][data-ready]').waitFor();
    await expect(body(page).locator('figure[data-note-image] img')).toHaveAttribute('src', /\/notes\/images\/[0-9a-f-]+$/);
    await expect(body(page).locator('ul > li')).toHaveText(['Nucleus']);
    await expect(body(page).locator('[data-page-break]')).toHaveCount(1);

    // The shortcuts, in a side panel like every other.
    await body(page).click();
    await key('Control+/');
    const shortcuts = page.getByRole('dialog', { name: 'Keyboard shortcuts' });
    await expect(shortcuts).toBeVisible();
    expect(await violations()).toEqual([]);
    await useSentinelTheme(page);
    expect(await foreignColours(page)).toEqual([]);
    await shortcuts.getByRole('button', { name: 'Close' }).click();
    await expect(shortcuts).toBeHidden();
});

/* Notes in and out (the owner's review, 2026-09-28): Markdown and text files in, Markdown and text out, formulas. */

const markdownFile = [
    '# Cell division', '',
    'Both **mitosis** and [meiosis](https://example.org/meiosis) divide a cell.', '',
    '> [!THEOREM]', '> Energy in a cell: $E = mc^2$.', '',
    '- [x] Read chapter 3', '- [ ] Draw the phases', '',
    '| Phase | Order |', '|---|---|', '| Prophase | 1 |', '',
    '$$', '\\sum_{i=1}^{n} i = \\frac{n(n+1)}{2}', '$$', '',
].join('\n');

test('a Markdown file is imported into an empty note, shown full width, and the note downloads as Markdown or as text', async ({ page }) => {
    await page.setViewportSize(desktop);
    const note = await openNote(page, 'empty');
    const view = () => page.locator('[data-note-page]');
    const importButton = page.getByRole('button', { name: 'Import' });
    await expect(view(page)).toHaveAttribute('data-page-view', 'pages');
    await expect(importButton).toBeVisible();
    await page.locator('[data-import-file]').setInputFiles({ name: 'Cell division.md', mimeType: 'text/markdown', buffer: Buffer.from(markdownFile) });
    await expect(page.getByRole('status').filter({ hasText: 'Cell division.md is in the note.' })).toBeVisible();
    // The file's first heading names the note; the rest is what the Markdown describes, full width rather than on A4 pages.
    await expect(page.getByLabel('Title')).toHaveValue('Cell division');
    await expect(body(page).locator('h1')).toHaveCount(0);
    await expect(body(page).locator('strong')).toHaveText('mitosis');
    await expect(body(page).locator('a[href="https://example.org/meiosis"]')).toHaveText('meiosis');
    await expect(body(page).locator('[data-callout][data-tone="theorem"]')).toContainText('Energy in a cell');
    await expect(body(page).locator('[data-callout] .katex')).toBeVisible();
    await expect(body(page).locator('li[data-checked="true"]')).toContainText('Read chapter 3');
    await expect(body(page).locator('table td').first()).toHaveText('Prophase');
    await expect(body(page).locator('[data-type="block-math"] .katex')).toBeVisible();
    await expect(view(page)).toHaveAttribute('data-page-view', 'continuous');
    // A file comes only into an empty note.
    await expect(importButton).toBeHidden();
    await expect(status(page)).toHaveText('Saved');

    // Full width is the note's own: kept with it, while other notes stay as they were.
    await page.reload();
    await page.locator('[data-note-editor][data-ready]').waitFor();
    await expect(page.getByLabel('Title')).toHaveValue('Cell division');
    await expect(body(page).locator('.katex')).toHaveCount(2);
    await expect(view(page)).toHaveAttribute('data-page-view', 'continuous');
    await expect(importButton).toBeHidden();
    await page.goto(note.url);
    await page.locator('[data-note-editor][data-ready]').waitFor();
    await expect(view(page)).toHaveAttribute('data-page-view', 'pages');
    await expect(importButton).toBeHidden();

    // Out again: Markdown any reader shows, named after the title, and the same as plain text.
    const markdown = await page.request.get(`${note.empty}/export/md`);
    expect([markdown.status(), markdown.headers()['content-type'], markdown.headers()['content-disposition']])
        .toEqual([200, 'text/markdown; charset=utf-8', 'attachment; filename="Cell division.md"']);
    const text = await markdown.text();
    expect(text.startsWith('# Cell division\n\n')).toBe(true);
    for (const piece of ['**mitosis**', '[meiosis](https://example.org/meiosis)', '> [!THEOREM]', '$E = mc^2$', '- [x] Read chapter 3', '- [ ] Draw the phases', '| Prophase | 1 |', '$$\n\\sum_{i=1}^{n} i = \\frac{n(n+1)}{2}\n$$']) {
        expect(text).toContain(piece);
    }
    const plain = await (await page.request.get(`${note.empty}/export/txt`)).text();
    expect(plain.startsWith('Cell division\n\n')).toBe(true);
    expect(plain).toContain('Theorem:');
    expect(plain).toContain('E = mc^2');
    expect(plain).not.toContain('**');
    expect(plain).not.toContain('$');
});

test('a formula from the Σ menu: written in LaTeX, drawn in the note, changed and removed', async ({ page }) => {
    await page.setViewportSize(desktop);
    await openNote(page, 'empty');
    const violations = async () => (await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze()).violations.map((v) => v.id);

    await body(page).click();
    await page.keyboard.type('Area: ');
    await page.getByRole('toolbar', { name: 'Formatting' }).getByRole('button', { name: 'Symbols', exact: true }).click();
    await page.getByRole('dialog', { name: 'Symbols' }).getByRole('button', { name: 'In the line' }).click();
    const panel = page.getByRole('dialog', { name: 'Formula in the line' });
    await expect(panel).toBeVisible();
    await panel.locator('[data-formula-latex]').fill('\\pi r^2');
    await expect(panel.locator('[data-formula-preview] .katex')).toBeVisible();
    expect(await violations()).toEqual([]);
    await panel.getByRole('button', { name: 'Apply' }).click();
    await expect(panel).toBeHidden();
    const formula = body(page).locator('span[data-type="inline-math"]');
    await expect(formula).toHaveAttribute('data-latex', '\\pi r^2');
    await expect(formula.locator('.katex')).toBeVisible();
    await expect(status(page)).toHaveText('Saved');
    await useSentinelTheme(page);
    expect(await foreignColours(page)).toEqual([]);

    // Clicking a formula opens the same panel to change it, or take it out.
    await formula.click();
    const edit = page.getByRole('dialog', { name: 'Edit the formula' });
    await expect(edit).toBeVisible();
    await expect(edit.locator('[data-formula-latex]')).toHaveValue('\\pi r^2');
    await edit.locator('[data-formula-latex]').fill('2\\pi r');
    await edit.locator('[data-formula-latex]').press('Control+Enter');
    await expect(edit).toBeHidden();
    await expect(formula).toHaveAttribute('data-latex', '2\\pi r');
    await formula.click();
    await edit.getByRole('button', { name: 'Remove' }).click();
    await expect(formula).toHaveCount(0);
    await expect(body(page)).toHaveText('Area: ');
    await expect(status(page)).toHaveText('Saved');
});

test('Markdown pasted as plain text becomes what it describes', async ({ page }) => {
    await page.setViewportSize(desktop);
    await openNote(page, 'empty');
    await body(page).click();
    await page.evaluate((markdown) => {
        const data = new DataTransfer();
        data.setData('text/plain', markdown);
        document.querySelector('.ProseMirror').dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true }));
    }, '## Phases\n\n1. Prophase\n2. Metaphase\n\nThe spindle **pulls** them apart.');
    await expect(body(page).locator('h2')).toHaveText('Phases');
    await expect(body(page).locator('ol > li')).toHaveText(['Prophase', 'Metaphase']);
    await expect(body(page).locator('strong')).toHaveText('pulls');
    await expect(status(page)).toHaveText('Saved');
});

test('A4 pages or full width is kept with each note, on every device', async ({ page }) => {
    await page.setViewportSize(desktop);
    const note = await openNote(page);
    const view = page.locator('[data-note-page]');
    await expect(view).toHaveAttribute('data-page-view', 'pages');
    await page.getByRole('button', { name: 'Toggle document view mode' }).click();
    await expect(view).toHaveAttribute('data-page-view', 'continuous');
    await expect.poll(() => serverText(page, note)).toContain('"view":"continuous"');

    // Another device, where nothing is remembered, opens it the same way.
    await page.evaluate(() => localStorage.removeItem('vistud.note-view-mode'));
    await page.reload();
    await page.locator('[data-note-editor][data-ready]').waitFor();
    await expect(view).toHaveAttribute('data-page-view', 'continuous');
});

test('a note moves to a window of its own, beside the study material, and back into ViStud', async ({ page }) => {
    await page.setViewportSize(desktop);
    const note = await openNote(page);
    const opening = page.waitForEvent('popup');
    await page.getByRole('button', { name: 'New window' }).click();
    const win = await opening;
    await win.waitForURL(/\?window=1$/);
    await win.locator('[data-note-editor][data-ready]').waitFor();
    // Only the note: no header bar or navigation.
    await expect(win.getByLabel('Title')).toHaveValue('Mitosis vs meiosis');
    await expect(win.locator('.note-header-bar')).toBeHidden();
    await expect(win.locator('.app-topbar, .app-sidebar, [data-back]')).toHaveCount(0);
    // The main tab goes back to where the note lives, free for the study material.
    await expect(page.getByRole('heading', { level: 1, name: 'Week 2: Cell division' })).toBeVisible();
    await expect(page.getByRole('status').filter({ hasText: '“Mitosis vs meiosis” is open in its own window.' })).toBeVisible();

    // It is the note, saving as anywhere else.
    await win.locator('.note-prose').click();
    await win.keyboard.press('Control+End');
    await win.keyboard.type(' Written beside the slides.');
    await expect.poll(() => serverText(page, note)).toContain('Written beside the slides.');
    const violations = (await new AxeBuilder({ page: win }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze()).violations.map((v) => v.id);
    expect(violations).toEqual([]);
    await useSentinelTheme(win);
    expect(await foreignColours(win)).toEqual([]);

    // Open in ViStud: the note back in the main tab, and its window closes.
    const closing = win.waitForEvent('close');
    await win.getByRole('button', { name: 'Open in ViStud' }).click();
    await closing;
    await page.waitForURL((url) => url.pathname === note.url && url.search === '');
    await page.locator('[data-note-editor][data-ready]').waitFor();
    await expect(body(page)).toContainText('Written beside the slides.');
});

test('printing and Save as PDF: only the note, on A4 with ViStud\'s footer instead of the browser\'s, in its colours', async ({ page }) => {
    await page.setViewportSize(desktop);
    const note = makeStudentWithNote();
    await openStudentHome(page, note.email);
    await page.evaluate(() => localStorage.setItem('vistud.appearance', 'dark'));
    await page.goto(note.url);
    await page.locator('[data-note-editor][data-ready]').waitFor();
    await body(page).click();
    await page.keyboard.press('Control+End');
    await page.keyboard.type('Key idea');
    await page.keyboard.press('Shift+Home');
    await page.getByRole('toolbar', { name: 'Formatting' }).getByRole('button', { name: 'Highlight', exact: true }).click();
    await page.getByRole('menu', { name: 'Highlight' }).getByRole('menuitemradio').first().click();
    await expect(status(page)).toHaveText('Saved');
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'vistud-dark');

    // The print dialog: the light theme (white paper), and the note's title as the PDF's name; both put back after.
    await page.evaluate(() => window.dispatchEvent(new Event('beforeprint')));
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'vistud-light');
    await expect(page).toHaveTitle('Mitosis vs meiosis');
    await page.emulateMedia({ media: 'print' });
    for (const around of ['.app-topbar', '.app-sidebar', '.note-header-bar', '.note-toolbar', '.note-count-bar']) {
        await expect(page.locator(around).first()).toBeHidden();
    }
    await expect(page.getByLabel('Title')).toBeVisible();
    await expect(body(page).locator('mark')).toHaveText('Key idea');
    const printed = await page.evaluate(() => ({
        exact: getComputedStyle(document.documentElement).printColorAdjust,
        highlight: getComputedStyle(document.querySelector('.note-prose mark')).backgroundColor,
        top: getComputedStyle(document.querySelector('.app-main')).paddingTop,
        sides: getComputedStyle(document.querySelector('.app-main')).paddingLeft,
        // The footer's title (@page in resources/css/editor.css): "Page 2 of 5" comes from the page counter.
        footer: getComputedStyle(document.documentElement).getPropertyValue('--print-title'),
    }));
    expect(printed.exact).toBe('exact');
    expect(printed.highlight).not.toBe('rgba(0, 0, 0, 0)');
    expect([printed.top, printed.sides, printed.footer]).toEqual(['75.5906px', '0px', '"Mitosis vs meiosis"']);
    const pdf = await page.pdf({ preferCSSPageSize: true });
    expect(pdf.toString('latin1')).toMatch(/\/MediaBox \[0 0 594\.9\d* 841\.9\d*\]/);
    await page.emulateMedia({ media: null });
    await page.evaluate(() => window.dispatchEvent(new Event('afterprint')));
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'vistud-dark');
    await expect(page).toHaveTitle(/^Mitosis vs meiosis · Biology/);
    expect(await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--print-title'))).toBe('');
});

/* Pages and page numbers (the owner's review, 2026-09-29): as in Word, a page ends with its number and a plain gap. */

const A4 = (297 / 25.4) * 96;
const GAP = 40; // --page-gap: 2.5rem

test('Pages: a page ends with its number and a plain gap; the status line follows the cursor and the scroll', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    const note = makeStudentWithNote();
    await openStudentHome(page, note.email);
    await writeLongNote(page, note);
    await page.goto(note.url);
    await page.locator('[data-note-editor][data-ready]').waitFor();
    const gaps = page.locator('.note-page-break');
    const status = page.locator('[data-note-count]');
    await expect(status).toContainText(/^Page 1 of \d+ · /);
    const total = (await gaps.count()) + 1;
    expect(total).toBeGreaterThanOrEqual(4);
    await expect(status).toContainText(`Page 1 of ${total} · `);

    // Nothing is written on a break, whether the writer made it or the editor did: no "End of page", no "Start of page".
    await expect(page.locator('[data-note-sheet]')).not.toContainText(/End of Page|Start of Page|Page Break ·|initial point/i);
    for (const auto of await page.locator('[data-auto-page-break]').all()) expect(await auto.textContent()).toBe('');
    // The one the writer made says what it is only when pointed at.
    const written = page.locator('[data-page-break] .page-break-badge');
    await expect(written).toHaveCSS('opacity', '0');
    await written.evaluate((badge) => badge.closest('[data-page-break]').scrollIntoView({ block: 'center' }));
    await page.locator('[data-page-break]').hover();
    await expect(written).toHaveCSS('opacity', '1');

    // Each page's number sits in the middle of its bottom margin, right above the gap that ends it; the last one is at the foot of the sheet.
    const geometry = await page.evaluate(() => {
        const sheet = document.querySelector('[data-note-sheet]').getBoundingClientRect();
        const numbers = [...document.querySelectorAll('.note-page-break')].map((gap) => {
            const number = gap.querySelector('.page-number').getBoundingClientRect();
            return { above: Math.round(gap.getBoundingClientRect().top - number.bottom), height: Math.round(gap.getBoundingClientRect().height), centred: Math.abs(number.left + number.width / 2 - (sheet.left + sheet.width / 2)) < 2 };
        });
        const foot = document.querySelector('.page-foot').getBoundingClientRect();
        return { numbers, foot: { above: Math.round(sheet.bottom - foot.bottom), centred: Math.abs(foot.left + foot.width / 2 - (sheet.left + sheet.width / 2)) < 2 }, sheet: sheet.height };
    });
    for (const number of geometry.numbers) expect([number.above >= 24 && number.above <= 42, number.height, number.centred]).toEqual([true, GAP, true]);
    expect(geometry.foot.above).toBeGreaterThanOrEqual(24);
    expect(geometry.foot.above).toBeLessThanOrEqual(42);
    expect(geometry.foot.centred).toBe(true);
    // The pages are true A4 sheets with the gaps between: the sheet is exactly as tall as that.
    expect(Math.abs(geometry.sheet - (total * A4 + (total - 1) * GAP))).toBeLessThan(4);

    // The cursor's page.
    await body(page).locator('p').last().click();
    await expect(status).toContainText(`Page ${total} of ${total} · `);
    await body(page).locator('h2').first().click();
    await expect(status).toContainText(`Page 1 of ${total} · `);
    // The page on view, as one scrolls: the second page's number in the middle of the window.
    await page.locator('.page-number').nth(1).evaluate((number) => number.scrollIntoView({ block: 'center' }));
    await expect(status).toContainText(`Page 2 of ${total} · `);
    await page.locator('.page-foot').evaluate((foot) => foot.scrollIntoView({ block: 'center' }));
    await expect(status).toContainText(`Page ${total} of ${total} · `);
});

test('Full Width is one document: no pages, no numbers, and a written break is a fine line', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    const note = makeStudentWithNote();
    await openStudentHome(page, note.email);
    await writeLongNote(page, note);
    await page.goto(note.url);
    await page.locator('[data-note-editor][data-ready]').waitFor();
    await expect(page.locator('[data-note-count]')).toContainText('Page 1 of ');
    await page.locator('[data-note-count]').click();
    const pages = page.locator('[data-stat-pages]');
    await expect(pages).toHaveText(/^\d+$/);
    await page.keyboard.press('Escape');

    await page.getByRole('button', { name: 'Toggle document view mode' }).click();
    await expect(page.locator('[data-note-page]')).toHaveAttribute('data-page-view', 'continuous');
    await expect(page.locator('[data-note-count]')).toHaveText(/^\d+ words · [\d,]+ characters$/);
    await expect(page.locator('[data-auto-page-break]')).toHaveCount(0);
    await expect(page.locator('.page-foot')).toBeHidden();
    await expect(page.locator('.page-number').first()).toBeHidden();
    // The break the writer made stays as a line with its name, as in Word's draft view.
    await expect(page.locator('[data-page-break]')).toBeVisible();
    await expect(page.locator('[data-page-break] .page-break-badge')).toBeVisible();
    await expect(page.locator('[data-page-break] .page-break-line').first()).toBeVisible();
    // How many pages it would take is only an estimate here.
    await page.locator('[data-note-count]').click();
    await expect(pages).toHaveText(/^≈ \d+$/);
    await page.keyboard.press('Escape');

    // And back: pages, numbers and the count return.
    await page.getByRole('button', { name: 'Toggle document view mode' }).click();
    await expect(page.locator('[data-note-count]')).toContainText(/^Page 1 of \d+ · /);
    await expect(page.locator('.page-foot')).toBeVisible();
});

test('printing leaves out the page numbers and gaps of the screen (the paper has its own footer)', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    const note = makeStudentWithNote();
    await openStudentHome(page, note.email);
    await writeLongNote(page, note);
    await page.goto(note.url);
    await page.locator('[data-note-editor][data-ready]').waitFor();
    await expect(page.locator('.page-number').first()).toBeVisible();
    await page.emulateMedia({ media: 'print' });
    await expect(page.locator('.page-foot')).toBeHidden();
    await expect(page.locator('.page-number').first()).toBeHidden();
    await expect(page.locator('[data-auto-page-break]').first()).toBeHidden();
    // The break the writer made is a page break in the printout, and nothing else.
    expect(await page.locator('[data-page-break]').evaluate((el) => [getComputedStyle(el).height, getComputedStyle(el).breakAfter])).toEqual(['0px', 'page']);
    await page.emulateMedia({ media: null });
});
