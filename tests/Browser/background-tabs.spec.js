import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { makeStudentWithStudyFiles, openStudentHome } from './support.js';

/*
 * Tabs opened and never looked at cost nothing (the owner's review, 2026-10-01): a file's preview and a note's
 * editor start only when the tab is seen, a note started late shows what another tab saved meanwhile, and a
 * busy server answers "preparing" at once and is asked again, so no request waits there.
 */

test.use({ viewport: { width: 1440, height: 900 } });

/** A tab the student hasn't turned to yet; tab.show() turns to it. */
async function backgroundTab(context) {
    const tab = await context.newPage();
    await tab.addInitScript(() => {
        let hidden = true;
        Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => (hidden ? 'hidden' : 'visible') });
        Object.defineProperty(document, 'hidden', { configurable: true, get: () => hidden });
        window.showTab = () => {
            hidden = false;
            document.dispatchEvent(new Event('visibilitychange'));
        };
    });
    tab.show = () => tab.evaluate(() => window.showTab());
    return tab;
}

const tinker = (code) => execFileSync(process.env.PHP_BINARY || 'php', ['artisan', 'tinker', '--execute', code], { stdio: 'pipe' });

test('a file opened in a background tab asks for nothing until it is seen', async ({ page, context }) => {
    const student = makeStudentWithStudyFiles();
    await openStudentHome(page, student.email);
    const tab = await backgroundTab(context);
    const asked = [];
    tab.on('request', (request) => {
        if (/\/(preview|content)$/.test(new URL(request.url()).pathname)) asked.push(request.url());
    });

    await tab.goto(student.docx);
    await tab.getByRole('heading', { level: 1 }).waitFor();
    await tab.goto(student.pdf);
    await tab.getByRole('heading', { level: 1 }).waitFor();
    await tab.waitForTimeout(2000);
    expect(asked).toEqual([]);

    await tab.show();
    await expect(tab.locator('iframe.file-preview')).toHaveAttribute('src', /\/content$/);
    expect(asked.length).toBe(1);
});

test('a note opened in a background tab starts when seen, with what another tab saved meanwhile', async ({ page, context }) => {
    const student = makeStudentWithStudyFiles();
    await openStudentHome(page, student.email);
    const tab = await backgroundTab(context);
    await tab.goto(student.note);
    await tab.waitForTimeout(1500);
    await expect(tab.locator('[data-note-editor][data-ready]')).toHaveCount(0);

    // Written in the other tab while this one waited.
    await page.goto(student.note);
    await page.locator('[data-note-editor][data-ready]').waitFor();
    await page.locator('.ProseMirror').click();
    await page.keyboard.press('Control+End');
    await page.keyboard.type(' Written in the other tab.');
    await expect(page.locator('[data-save-status]')).toHaveText('Saved');

    await tab.show();
    await tab.locator('[data-note-editor][data-ready]').waitFor();
    await expect(tab.locator('.ProseMirror')).toContainText('Written in the other tab.');
});

test('when every conversion slot is taken the server says so at once, and the page asks again', async ({ page }) => {
    // The slots are taken before the files are uploaded: an upload queues its preview straight away (App\Jobs\MakeFilePreview).
    tinker("Cache::lock('vistud:office-slot:1', 120)->get(); Cache::lock('vistud:office-slot:2', 120)->get();");
    try {
        const student = makeStudentWithStudyFiles();
        await openStudentHome(page, student.email);
        const answers = [];
        page.on('response', (response) => {
            if (response.url().endsWith('/preview') && response.request().method() === 'HEAD') answers.push(response.status());
        });
        const started = Date.now();
        await page.goto(student.docx);
        await expect.poll(() => answers.length, { timeout: 10_000 }).toBeGreaterThanOrEqual(2);
        expect(answers.slice(0, 2)).toEqual([202, 202]);
        expect(Date.now() - started).toBeLessThan(10_000);
    } finally {
        tinker("Cache::lock('vistud:office-slot:1')->forceRelease(); Cache::lock('vistud:office-slot:2')->forceRelease();");
    }
    // A slot free again: the preview comes on its own.
    await expect(page.locator('iframe[data-pdf-made]')).toHaveAttribute('src', /\/preview$/, { timeout: 30_000 });
});
