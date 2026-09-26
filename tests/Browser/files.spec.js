import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { fileURLToPath } from 'node:url';
import { foreignColours, makeStudentWithModules, openStudentHome, THEMES, useSentinelTheme, useTheme } from './support.js';

/* Uploading files, and the file page (docs/specs/workspaces.md step 4). */

const desktop = { width: 1440, height: 900 };
const phone = { width: 390, height: 844 };
const fixture = (name) => fileURLToPath(new URL(`./fixtures/files/${name}`, import.meta.url));
const dialog = (page) => page.locator('#structure-dialog');
const week1 = (page) => page.locator('.module-card').filter({ has: page.getByText('Week 1: Cells', { exact: true }) });
const analyse = async (page) => (await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze())
    .violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target).join(' ')}`);

test.use({ reducedMotion: 'reduce' });
test.describe.configure({ timeout: 60_000 });

/** A student with Biology's modules, and three files uploaded into Week 1 (one more refused). */
async function withFiles(page) {
    await openStudentHome(page, makeStudentWithModules());
    await page.locator('main').getByRole('link', { name: 'Biology' }).click();
    await page.getByRole('heading', { level: 1, name: 'Biology' }).waitFor();
    await page.goto(page.url().replace(/\/?$/, '/modules'));
    await page.getByRole('heading', { level: 1, name: 'Modules' }).waitFor();
    await page.waitForLoadState('load');

    await week1(page).getByRole('button', { name: 'Upload files' }).click();
    await expect(dialog(page).getByRole('heading', { name: 'Upload files to Week 1: Cells' })).toBeVisible();
    await dialog(page).locator('input[type="file"]').setInputFiles([
        fixture('Lecture 2 - cell division.pdf'),
        fixture('Essay - why cells divide.docx'),
        fixture('Onion cells.png'),
        fixture('Homework with macros.docx'),
    ]);
    await expect(dialog(page).getByRole('list', { name: 'Chosen files' }).getByRole('listitem')).toHaveCount(4);
    await dialog(page).getByRole('button', { name: 'Upload', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: '3 files uploaded.' })).toBeVisible();
}

test('a student uploads files; the ones that aren\'t safe are refused with the reason', async ({ page }) => {
    await page.setViewportSize(desktop);
    await withFiles(page);

    await expect(dialog(page)).toContainText('Homework with macros.docx: This file contains macros');
    await page.keyboard.press('Escape');
    await expect(dialog(page)).toBeHidden();
    await expect(week1(page)).toContainText('3 files');
    for (const name of ['Lecture 2 - cell division.pdf', 'Essay - why cells divide.docx', 'Onion cells.png']) {
        await expect(week1(page).getByRole('link', { name })).toBeVisible();
    }
    await expect(week1(page)).toContainText('Word document · ');
});

test('the file page previews a PDF or an image, and offers other files as a download', async ({ page }) => {
    await page.setViewportSize(desktop);
    await withFiles(page);
    await page.keyboard.press('Escape');

    await week1(page).getByRole('link', { name: 'Lecture 2 - cell division.pdf' }).click();
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Lecture 2 - cell division.pdf');
    await expect(page.getByRole('navigation', { name: 'Where this file is' })).toHaveText(/Biology\s*Week 1: Cells/);
    const frame = page.locator('iframe.file-preview');
    await expect(frame).toBeVisible();
    const box = await frame.boundingBox();
    expect(box.height).toBeGreaterThan(500);
    const bytes = await page.request.get(await frame.getAttribute('src'));
    expect([bytes.status(), bytes.headers()['content-type'], bytes.headers()['x-content-type-options']]).toEqual([200, 'application/pdf', 'nosniff']);

    await page.goBack();
    await week1(page).getByRole('link', { name: 'Onion cells.png' }).click();
    await expect(page.getByRole('img', { name: 'Onion cells.png' })).toBeVisible();

    await page.goBack();
    await week1(page).getByRole('link', { name: 'Essay - why cells divide.docx' }).click();
    await expect(page.getByRole('heading', { name: 'No preview for Word document files yet' })).toBeVisible();
    const download = page.waitForEvent('download');
    await page.getByRole('main').getByRole('link', { name: 'Download' }).last().click();
    expect((await download).suggestedFilename()).toBe('Essay - why cells divide.docx');
});

for (const [name, viewport] of Object.entries({ desktop, phone })) {
    test(`every colour in the upload dialog and on a file page comes from a token: ${name}`, async ({ page }) => {
        await page.setViewportSize(viewport);
        await withFiles(page);
        await useSentinelTheme(page);
        const states = { 'upload dialog, one refused': await foreignColours(page) };
        await page.keyboard.press('Escape');

        await week1(page).getByRole('link', { name: 'Essay - why cells divide.docx' }).click();
        await page.getByRole('heading', { level: 1 }).waitFor();
        await useSentinelTheme(page);
        states['file page, no preview'] = await foreignColours(page);

        for (const [state, colours] of Object.entries(states)) {
            expect(colours, `${state}: colours not from a token`).toEqual([]);
        }
    });
}

for (const theme of THEMES) {
    test(`axe finds no violations in uploads and on file pages: ${theme}`, async ({ page }) => {
        await page.setViewportSize(desktop);
        await withFiles(page);
        await useTheme(page, theme);
        expect(await analyse(page)).toEqual([]);
        await page.keyboard.press('Escape');

        for (const file of ['Lecture 2 - cell division.pdf', 'Essay - why cells divide.docx']) {
            await week1(page).getByRole('link', { name: file }).click();
            await page.getByRole('heading', { level: 1, name: file }).waitFor();
            await useTheme(page, theme);
            expect(await analyse(page)).toEqual([]);
            await page.goBack();
        }
    });
}

test('a file page never scrolls sideways at 320 px, even with 200% text', async ({ page }) => {
    await withFiles(page);
    await page.keyboard.press('Escape');
    await week1(page).getByRole('link', { name: 'Essay - why cells divide.docx' }).click();
    await page.getByRole('heading', { level: 1 }).waitFor();
    await page.setViewportSize({ width: 320, height: 800 });
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
});
