import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { foreignColours, makeStudentWithNote, openStudentHome, THEMES, useSentinelTheme, useTheme } from './support.js';

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

async function typeAtEnd(page, text) {
    await body(page).click();
    await page.keyboard.press('Control+End');
    await page.keyboard.type(text);
}

test('a student writes a note: it saves by itself, and the title names the page', async ({ page }) => {
    await page.setViewportSize(desktop);
    const note = await openNote(page, 'empty');
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Untitled note');
    await expect(page.getByRole('navigation', { name: 'Where this note is' })).toHaveText(/Biology\s*Week 1: Cells\s*Labs/);

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
    await tool('Heading').click();
    await page.keyboard.type('Microscopes');
    await expect(body(page).locator('h2')).toHaveText('Microscopes');
    await expect(tool('Heading')).toHaveAttribute('aria-pressed', 'true');

    await page.keyboard.press('Enter');
    await page.keyboard.press('Control+b');
    await page.keyboard.type('Focus');
    await expect(body(page).locator('strong')).toHaveText('Focus');
    await expect(tool('Bold (Ctrl+B)')).toHaveAttribute('aria-pressed', 'true');

    await page.keyboard.press('Enter');
    await tool('To-do list').click();
    await page.keyboard.type('Draw what I see');
    await body(page).getByRole('checkbox').check();
    await expect(body(page).locator('li[data-checked="true"] > div')).toHaveText('Draw what I see');

    // One tab stop: the arrow keys move along the toolbar.
    await tool('Bold (Ctrl+B)').focus();
    await page.keyboard.press('ArrowRight');
    await expect(tool('Italic (Ctrl+I)')).toBeFocused();
    await page.keyboard.press('End');
    await expect(tool('Undo (Ctrl+Z)')).toBeFocused();
    await expect(status(page)).toHaveText('Saved');
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

test('a note saved in another tab meanwhile is a conflict, and nothing is overwritten silently', async ({ page, context }) => {
    await page.setViewportSize(desktop);
    const note = await openNote(page);
    const other = await context.newPage();
    await other.setViewportSize(desktop);
    await other.goto(note.url);
    await other.locator('[data-note-editor][data-ready]').waitFor();

    await typeAtEnd(page, ' From the first tab.');
    await expect(status(page)).toHaveText('Saved');

    await typeAtEnd(other, ' From the second tab.');
    await expect(status(other)).toHaveText('Conflict, needs your choice');
    await expect(alert(other, 'conflict')).toBeVisible();
    await other.getByRole('button', { name: 'Keep my version' }).click();
    await expect(status(other)).toHaveText('Saved');
    expect(await serverText(page, note)).toContain('From the second tab.');

    await typeAtEnd(page, ' Again.');
    await expect(alert(page, 'conflict')).toBeVisible();
    await page.getByRole('button', { name: 'Use the newer version' }).click();
    await expect(body(page)).toContainText('From the second tab.');
    await expect(body(page)).not.toContainText('Again.');
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
    await page.goto(`/workspaces/${note.workspace}/notes`);
    await page.getByRole('heading', { level: 1, name: 'Notes & files' }).waitFor();
    await page.waitForLoadState('load');
    return note;
}

test('Notes & files: a new note, the recent list, and the trash', async ({ page }) => {
    await page.setViewportSize(desktop);
    await openNotesAndFiles(page);
    await expect(page.getByRole('region', { name: 'Recently edited' })).toContainText('Mitosis vs meiosis');

    await page.getByRole('button', { name: 'New note' }).click();
    await page.locator('[data-note-editor][data-ready]').waitFor();
    await page.getByLabel('Title').fill('Exam plan');
    await expect(status(page)).toHaveText('Saved');
    await page.getByRole('link', { name: 'Biology' }).first().click();

    const loose = page.getByRole('region', { name: 'Not in a module' });
    await expect(loose.getByRole('link', { name: 'Exam plan' })).toBeVisible();
    await loose.getByRole('button', { name: 'Actions for Exam plan' }).click();
    await loose.getByRole('button', { name: 'Move to trash' }).click();
    await expect(page.getByRole('status').filter({ hasText: '“Exam plan” is in the trash.' })).toBeVisible();

    await page.getByRole('button', { name: 'Trash (1)' }).click();
    await page.getByRole('button', { name: 'Restore Exam plan' }).click();
    await expect(loose.getByRole('link', { name: 'Exam plan' })).toBeVisible();
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
