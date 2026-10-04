import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { fileURLToPath } from 'node:url';
import { foreignColours, makeStudentWithModules, newHere, openTab, openStudentHome, THEMES, useSentinelTheme, useTheme } from './support.js';

/* Uploading files, and the file page (docs/specs/workspaces.md step 4). */

const desktop = { width: 1440, height: 900 };
const phone = { width: 390, height: 844 };
const fixture = (name) => fileURLToPath(new URL(`./fixtures/files/${name}`, import.meta.url));
const dialog = (page) => page.locator('#structure-dialog');
// Week 1's own page: its notes, files and links.
const week1 = (page) => page.getByRole('list', { name: 'Notes, files and links' });
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
    await page.locator('main').getByRole('link', { name: 'Week 1: Cells' }).click();
    await page.getByRole('heading', { level: 1, name: 'Week 1: Cells' }).waitFor();
    await page.waitForLoadState('load');

    await newHere(page, 'Upload files');
    await expect(dialog(page).getByRole('heading', { name: 'Upload files to Week 1: Cells' })).toBeVisible();
    // Chosen files go up one at a time, straight away (resources/js/uploader.js).
    await dialog(page).locator('input[data-upload-files]').setInputFiles([
        fixture('Lecture 2 - cell division.pdf'),
        fixture('Essay - why cells divide.docx'),
        fixture('Onion cells.png'),
        fixture('Homework with macros.docx'),
    ]);
    await expect(dialog(page)).toContainText('3 files uploaded, 1 not uploaded.', { timeout: 20_000 });
}

test('a student uploads files; the ones that aren\'t safe are refused with the reason', async ({ page }) => {
    await page.setViewportSize(desktop);
    await withFiles(page);

    const refused = dialog(page).locator('.upload-item').filter({ hasText: 'Homework with macros.docx' });
    await expect(refused).toContainText('This file contains macros');
    await page.keyboard.press('Escape');
    await expect(dialog(page)).toBeHidden();
    await expect(week1(page).locator('.item-row')).toHaveCount(3);
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
    await expect(page.getByRole('navigation', { name: 'Where this file is' })).toHaveText(/Modules\s*Week 1: Cells/);
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
    // Word is shown as a PDF made by LibreOffice where it is installed (App\Study\FilePreviews), and can always be downloaded.
    await expect(page.locator('iframe[src$="/preview"]')).toHaveCount(1);
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
        // The page moves in place: the test theme goes on once the file's own page has arrived.
        await page.getByRole('heading', { level: 1, name: 'Essay - why cells divide.docx' }).waitFor();
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
    await page.getByRole('heading', { level: 1, name: 'Essay - why cells divide.docx' }).waitFor();
    await page.setViewportSize({ width: 320, height: 800 });
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
});

test('a Markdown file is shown as it was meant to look, and opens as a note', async ({ page }) => {
    await page.setViewportSize(desktop);
    await withFiles(page);
    await page.keyboard.press('Escape');
    await expect(dialog(page)).toBeHidden();
    await newHere(page, 'Upload files');
    await dialog(page).locator('input[data-upload-files]').setInputFiles({
        name: 'Cells.md',
        mimeType: 'text/markdown',
        buffer: Buffer.from('# Cells\n\nA **bold** claim: $E = mc^2$.\n\n| Part | Job |\n|---|---|\n| Nucleus | DNA |\n\n<script>alert(1)</script>\n'),
    });
    await expect(page.getByRole('status').filter({ hasText: '1 file uploaded.' })).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(dialog(page)).toBeHidden();

    // Headings, bold, a table and a formula as they were meant to look; the script tag is gone.
    await week1(page).getByRole('link', { name: 'Cells.md' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Cells.md' })).toBeVisible();
    const preview = page.locator('.file-markdown');
    await expect(preview.locator('h1')).toHaveText('Cells');
    await expect(preview.locator('strong')).toHaveText('bold');
    await expect(preview.locator('table td').first()).toHaveText('Nucleus');
    await expect(preview.locator('.katex')).toBeVisible();
    await expect(preview).not.toContainText('alert');
    expect(await analyse(page)).toEqual([]);
    await useSentinelTheme(page);
    expect(await foreignColours(page)).toEqual([]);

    // Open as a note: a new note in Week 1 made from the file, which stays as it is.
    await page.getByRole('link', { name: 'Open as a note' }).click();
    await page.locator('[data-note-editor][data-ready]').waitFor();
    await expect(page.getByLabel('Title')).toHaveValue('Cells');
    await expect(page.locator('.note-prose strong')).toHaveText('bold');
    await expect(page.locator('.note-prose .katex')).toBeVisible();
    await expect(page.getByRole('navigation', { name: 'Where this note is' })).toHaveText(/Modules\s*Week 1: Cells/);
    await expect(page.locator('[data-save-status]')).toHaveText('Saved');
});

test('Take notes opens the file\'s own note beside it, in the same place, and moves it to a window saved', async ({ page }) => {
    await page.setViewportSize(desktop);
    await withFiles(page);
    await page.keyboard.press('Escape');
    await week1(page).getByRole('link', { name: 'Lecture 2 - cell division.pdf' }).click();
    await page.getByRole('heading', { level: 1, name: 'Lecture 2 - cell division.pdf' }).waitFor();

    // On a computer the note opens in a pane beside the file, named after it, and is made straight away.
    await page.getByRole('link', { name: 'Take notes' }).click();
    const pane = page.frameLocator('[data-note-pane] iframe');
    await pane.locator('[data-note-editor][data-ready]').waitFor();
    await expect(pane.getByLabel('Title')).toHaveValue('Lecture 2 - cell division (notes)');
    await expect(pane.locator('[data-save-status]')).toHaveText('Saved');
    await pane.locator('.ProseMirror').click();
    await page.keyboard.type('Prophase comes first.');

    // Its own window: everything written is saved first, and the window shows that same note.
    const opening = page.waitForEvent('popup');
    await page.locator('[data-note-pane-pop-out]').click();
    const win = await opening;
    await win.waitForURL(/\/notes\/[0-9a-f-]+\?window=1$/);
    await win.locator('[data-note-editor][data-ready]').waitFor();
    await expect(win.getByLabel('Title')).toHaveValue('Lecture 2 - cell division (notes)');
    await expect(win.locator('.ProseMirror')).toContainText('Prophase comes first.');
    await expect(page.locator('[data-note-pane]')).toBeHidden();

    // Shared from its window: where the browser can't share files, the file is downloaded.
    const downloading = win.waitForEvent('download');
    await win.locator('.note-bar-tools').getByRole('button', { name: 'Share' }).click();
    await win.locator('#note-share-menu-small').getByRole('button', { name: /Markdown/ }).click();
    const download = await downloading;
    expect(download.suggestedFilename()).toBe('Lecture 2 - cell division (notes).md');
    await win.close();

    // The note is in Week 1 with the file, and Take notes opens it again rather than a second one.
    await page.reload();
    await page.getByRole('link', { name: 'Take notes' }).click();
    await expect(pane.locator('.ProseMirror')).toContainText('Prophase comes first.');
    await page.getByRole('link', { name: 'Back to Week 1: Cells' }).click();
    await openTab(page, 'Notes');
    await expect(week1(page).getByRole('link', { name: 'Lecture 2 - cell division (notes)' })).toHaveCount(1);
});
