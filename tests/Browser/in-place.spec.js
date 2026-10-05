import { test, expect } from '@playwright/test';
import { makeStudentWithNote, openStudentHome } from './support.js';

/*
| Pages move without reloading (the owner's review, 2026-09-28;
| resources/js/page.js): a link swaps the next page in, and the page keeps
| working as if it had been opened on its own.
*/

test.use({ reducedMotion: 'reduce' });

const sidebar = (page) => page.locator('.app-sidebar');

/** A mark on the window: it survives a page swapped in, and a reload wipes it. */
const mark = (page) => page.evaluate(() => { window.__stayed = true; });
const stayed = (page) => page.evaluate(() => window.__stayed === true);

function watchErrors(page) {
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    return errors;
}

test('links move between pages without reloading, and each page works as if opened on its own', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    const errors = watchErrors(page);
    const note = makeStudentWithNote();
    await openStudentHome(page, note.email);
    await page.goto(`/courses/${note.workspace}`);
    await mark(page);

    await sidebar(page).getByRole('link', { name: 'Modules', exact: true }).click();
    await expect(page).toHaveURL(/\/modules$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Modules' })).toBeVisible();
    await page.getByRole('link', { name: 'Week 1: Cells' }).first().click();
    await expect(page.getByRole('heading', { level: 1, name: 'Week 1: Cells' })).toBeVisible();
    await page.getByRole('navigation', { name: 'This module' }).getByRole('link', { name: /^Files/ }).click();
    await page.getByRole('link', { name: 'Labs', exact: true }).first().click();
    await expect(page.getByRole('heading', { level: 1, name: 'Labs' })).toBeVisible();
    expect(await stayed(page)).toBe(true);

    // The page swapped in works: its menus open, its Livewire actions answer, and the tab's title follows.
    await expect(page).toHaveTitle(/Labs/);
    await page.getByRole('button', { name: 'More for Labs' }).click();
    await page.locator('#page-menu').getByRole('button', { name: 'Rename' }).click();
    await expect(page.locator('#structure-dialog')).toBeVisible();
    await page.keyboard.press('Escape');

    // The browser's own Back and Forward step through the pages, still without reloading.
    await page.goBack();
    await expect(page.getByRole('heading', { level: 1, name: 'Week 1: Cells' })).toBeVisible();
    await page.goForward();
    await expect(page.getByRole('heading', { level: 1, name: 'Labs' })).toBeVisible();
    expect(await stayed(page)).toBe(true);
    expect(errors).toEqual([]);
});

test('the chosen theme and a collapsed sidebar stay as they are from page to page', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    const note = makeStudentWithNote();
    await openStudentHome(page, note.email);
    await page.evaluate(() => {
        localStorage.setItem('vistud.appearance', 'dark');
        localStorage.setItem('vistud.sidebar', 'collapsed');
    });
    await page.goto(`/courses/${note.workspace}`);
    const root = page.locator('html');
    await expect(root).toHaveAttribute('data-appearance', 'dark');
    const dark = await root.getAttribute('data-theme');
    await mark(page);

    await sidebar(page).getByRole('link', { name: 'Modules', exact: true }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Modules' })).toBeVisible();
    expect(await stayed(page)).toBe(true);
    await expect(root).toHaveAttribute('data-appearance', 'dark');
    await expect(root).toHaveAttribute('data-theme', dark);
    await expect(root).toHaveAttribute('data-sidebar', 'collapsed');
});

test('leaving a note without reloading saves what was written, and the editor stops with its page', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    const errors = watchErrors(page);
    const note = makeStudentWithNote();
    await openStudentHome(page, note.email);
    await page.goto(note.url);
    const modulePage = await page.getByRole('link', { name: /^Back to / }).getAttribute('href');
    await page.goto(modulePage);
    await mark(page);

    // Into the note in place: the editor starts on the page swapped in.
    await page.getByRole('link', { name: 'Mitosis vs meiosis' }).first().click();
    await page.locator('[data-note-editor][data-ready]').waitFor();
    expect(await stayed(page)).toBe(true);
    await page.locator('.note-prose').click();
    await page.keyboard.press('Control+End');
    await page.keyboard.type(' Written just before leaving.');

    // Straight out again, before the autosave's pause: what was written is sent on the way out.
    await page.getByRole('link', { name: /^Back to / }).click();
    await expect(page).toHaveURL(modulePage);
    expect(await stayed(page)).toBe(true);
    const api = note.url.replace(/^\/(?:courses|workspaces)\/[^/]+\/notes\//, '/api/v1/notes/');
    await expect.poll(async () => JSON.stringify((await (await page.request.get(api)).json()).doc)).toContain('Written just before leaving.');

    // The editor's keys went with it: Ctrl+F here is the browser's again, not the note's find bar.
    await page.keyboard.press('Control+f');
    await expect(page.locator('[data-find-bar]')).toHaveCount(0);

    // And coming back to the note starts a fresh editor with the saved text.
    await page.getByRole('link', { name: 'Mitosis vs meiosis' }).first().click();
    await page.locator('[data-note-editor][data-ready]').waitFor();
    await expect(page.locator('.note-prose')).toContainText('Written just before leaving.');
    expect(errors).toEqual([]);
});

test('Back and Forward never show a stale page: a list catches up, and a note opens as last saved', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    const errors = watchErrors(page);
    const note = makeStudentWithNote();
    await openStudentHome(page, note.email);
    await page.goto(note.url);
    const modulePage = await page.getByRole('link', { name: /^Back to / }).getAttribute('href');
    await page.goto(modulePage);
    await mark(page);

    // Into the note, a change, and the browser's own Back: the note's row there says it was just edited.
    await page.getByRole('link', { name: 'Mitosis vs meiosis' }).first().click();
    await page.locator('[data-note-editor][data-ready]').waitFor();
    await page.getByLabel('Title').fill('Mitosis and meiosis');
    await expect(page.locator('[data-save-status]')).toHaveText('Saved');
    await page.goBack();
    await expect(page).toHaveURL(modulePage);
    await expect(page.getByRole('link', { name: 'Mitosis and meiosis' })).toBeVisible();
    expect(await stayed(page)).toBe(true);

    // Forward into the note: opened fresh, with the title just saved, never an old copy.
    await page.goForward();
    await page.locator('[data-note-editor][data-ready]').waitFor();
    await expect(page.getByLabel('Title')).toHaveValue('Mitosis and meiosis');
    expect(errors).toEqual([]);
});

test('a page that takes a moment shows a thin loading line, gone once it is in', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    const note = makeStudentWithNote();
    await openStudentHome(page, note.email);
    await page.goto(`/courses/${note.workspace}`);
    await page.route('**/modules', async (route) => {
        await new Promise((done) => setTimeout(done, 1200));
        await route.continue();
    });
    const line = page.locator('.page-loading');
    await expect(line).toHaveAttribute('aria-hidden', 'true');
    await sidebar(page).getByRole('link', { name: 'Modules', exact: true }).click();
    await expect(line).toHaveClass(/is-loading/);
    await expect(page.getByRole('heading', { level: 1, name: 'Modules' })).toBeVisible();
    await expect(line).not.toHaveClass(/is-loading|is-done/);
});

test('a notice from the page before stays in view on the page after', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    const note = makeStudentWithNote();
    await openStudentHome(page, note.email);
    await page.goto(note.url);
    await page.locator('[data-note-editor][data-ready]').waitFor();
    await mark(page);
    await page.getByRole('button', { name: 'Actions for Mitosis vs meiosis' }).click();
    await page.getByRole('button', { name: 'Move to trash' }).click();
    await expect(page).toHaveURL(/\/notes$/);
    await expect(page.getByRole('status').filter({ hasText: 'is in the trash.' })).toBeVisible();
    expect(await stayed(page)).toBe(true);
});
