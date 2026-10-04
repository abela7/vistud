import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { foreignColours, makeStudentWithSession, makeStudentWithTopics, openStudentHome, THEMES, useSentinelTheme, useTheme } from './support.js';

/* Teaching choices, material and the briefing any AI receives (docs/specs/study-memory.md §4.3). */

const desktop = { width: 1440, height: 900 };
const phone = { width: 390, height: 844 };
const dialog = (page) => page.locator('#session-dialog');
const analyse = async (page) => (await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze())
    .violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target).join(' ')}`);

test.use({ reducedMotion: 'reduce' });
test.describe.configure({ timeout: 60_000 });

async function openSession(page) {
    const student = makeStudentWithSession();
    await openStudentHome(page, student.email);
    await page.goto(`/workspaces/${student.workspace}/sessions/${student.session}`);
    await page.getByRole('heading', { level: 1, name: 'Joins' }).waitFor();
    await page.waitForLoadState('load');
    return student;
}

async function openBriefing(page) {
    await page.getByRole('button', { name: 'Another AI', exact: true }).click();
    await dialog(page).getByRole('heading', { name: 'Study with an AI' }).waitFor();
}

async function openTeaching(page) {
    await page.getByRole('button', { name: 'Actions for this session' }).click();
    await page.locator('.row-menu:not([hidden])').getByRole('button', { name: 'How the AI teaches' }).click();
    await dialog(page).getByLabel('How to teach').waitFor();
}

test('the briefing is copied and downloaded, with the note the student chose', async ({ page, context }) => {
    await context.grantPermissions(['clipboard-read', 'clipboard-write']);
    await page.setViewportSize(desktop);
    await openSession(page);

    await page.getByRole('button', { name: 'Notes & files', exact: true }).click();
    const use = dialog(page).getByRole('button', { name: 'Use Lecture 3: joins in the briefing' });
    await expect(use).toHaveAttribute('aria-pressed', 'false');
    await use.click();
    await expect(use).toHaveAttribute('aria-pressed', 'true');
    await dialog(page).getByRole('button', { name: 'Done' }).click();
    await dialog(page).waitFor({ state: 'hidden' });
    await expect(page.getByRole('button', { name: 'Notes & files', exact: true })).toHaveAccessibleDescription('1 for the AI');

    await openBriefing(page);
    const text = dialog(page).getByLabel('Briefing text');
    await expect(text).toContainText('# You are the student\'s tutor');
    await expect(text).toContainText('## This session');
    await expect(text).toContainText('## The student\'s note: Lecture 3: joins');

    await dialog(page).getByRole('button', { name: 'Copy' }).click();
    await expect(dialog(page).getByRole('button', { name: 'Copied' })).toBeVisible();
    expect(await page.evaluate(() => navigator.clipboard.readText())).toContain('## What the student has recorded about Joins');

    const [download] = await Promise.all([page.waitForEvent('download'), dialog(page).getByRole('link', { name: 'Download' }).click()]);
    expect(download.suggestedFilename()).toMatch(/^vistud-briefing-joins-databases-\d{4}-\d{2}-\d{2}\.md$/);
});

test('how the AI teaches is chosen at the start and changed during the session', async ({ page }) => {
    await page.setViewportSize(desktop);
    const student = makeStudentWithTopics();
    await openStudentHome(page, student.email);
    await page.goto(`/workspaces/${student.workspace}`);
    await page.waitForLoadState('load');
    await page.getByRole('button', { name: 'Start studying' }).first().click();
    const start = page.locator('#study-dialog');
    await expect(start).toContainText('Explain, then check · checks after every section');
    await start.getByRole('button', { name: 'Change' }).click();
    await start.getByLabel('How to teach').selectOption({ label: 'Socratic' });
    await start.getByRole('button', { name: 'Start' }).click();
    await page.getByRole('heading', { level: 1, name: 'Study session' }).waitFor();

    await openTeaching(page);
    await expect(dialog(page).getByLabel('How to teach')).toHaveValue('socratic');
    await dialog(page).getByLabel('Quiz level').selectOption({ label: 'Exam level' });
    await dialog(page).getByRole('button', { name: 'Save' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'The briefing now asks for this way of teaching.' })).toBeVisible();
    await dialog(page).waitFor({ state: 'hidden' });
    await openBriefing(page);
    await expect(dialog(page).getByLabel('Briefing text')).toContainText('Questions are exam-style');
});

for (const [name, viewport] of Object.entries({ desktop, phone })) {
    test(`every colour of the briefing and teaching comes from a token: ${name}`, async ({ page }) => {
        await page.setViewportSize(viewport);
        await openSession(page);
        await useSentinelTheme(page);
        const states = { 'session page': await foreignColours(page) };
        await openBriefing(page);
        states['briefing dialog'] = await foreignColours(page);
        await page.keyboard.press('Escape');
        await dialog(page).waitFor({ state: 'hidden' });
        await openTeaching(page);
        states['teaching dialog'] = await foreignColours(page);
        for (const [state, colours] of Object.entries(states)) {
            expect(colours, `${state}: colours not from a token`).toEqual([]);
        }
    });
}

for (const theme of THEMES) {
    test(`axe finds no violations in the briefing and teaching: ${theme}`, async ({ page }) => {
        await page.setViewportSize(desktop);
        await openSession(page);
        await useTheme(page, theme);
        await openBriefing(page);
        expect(await analyse(page)).toEqual([]);
        await page.keyboard.press('Escape');
        await dialog(page).waitFor({ state: 'hidden' });
        await openTeaching(page);
        expect(await analyse(page)).toEqual([]);
    });
}

test('the briefing never scrolls the page sideways at 320 px, even with 200% text', async ({ page }) => {
    await openSession(page);
    await page.setViewportSize({ width: 320, height: 800 });
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    await openBriefing(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
    expect(await dialog(page).evaluate((el) => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
});
