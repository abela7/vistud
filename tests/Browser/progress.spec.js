import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { foreignColours, makeStudentWithTopics, openStudentHome, THEMES, useSentinelTheme, useTheme } from './support.js';

/* The Progress section: topics, statuses and questions (docs/specs/study-memory.md §3). */

const desktop = { width: 1440, height: 900 };
const phone = { width: 390, height: 844 };
const dialog = (page) => page.locator('#progress-dialog');
const row = (page, name) => page.locator('.topic-row').filter({ has: page.getByText(name, { exact: true }) });
const analyse = async (page) => (await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze())
    .violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target).join(' ')}`);

test.use({ reducedMotion: 'reduce' });
test.describe.configure({ timeout: 60_000 });

async function openProgress(page) {
    const student = makeStudentWithTopics();
    await openStudentHome(page, student.email);
    await page.goto(`/workspaces/${student.workspace}/progress`);
    await page.getByRole('heading', { level: 1, name: 'Progress' }).waitFor();
    await page.waitForLoadState('load');
    return student;
}

test('a student tracks topics and questions', async ({ page }) => {
    await page.setViewportSize(desktop);
    await openProgress(page);
    await expect(page.getByRole('list', { name: 'Topics by status' }).getByRole('listitem')).toHaveText(['1 not started', '0 covered', '1 understood', '1 confused', '0 mastered']);

    // The student's word, and the honest evidence line under it.
    await row(page, 'Normalisation').getByRole('button', { name: 'Covered' }).click();
    await expect(row(page, 'Normalisation')).toContainText('Evidence: seen, not practised');
    await row(page, 'Normalisation').getByRole('button', { name: 'Understood' }).click();
    await expect(row(page, 'Normalisation')).toContainText('not practised yet');
    await expect(row(page, 'Normalisation').getByRole('button', { name: 'Understood' })).toHaveAttribute('aria-pressed', 'true');

    await page.getByRole('button', { name: 'New topic' }).click();
    await expect(dialog(page).getByLabel('Name')).toBeFocused();
    await dialog(page).getByLabel('Name').fill('Transactions');
    await dialog(page).getByLabel('Module').selectOption({ label: 'Week 1: Relational model' });
    await dialog(page).getByRole('button', { name: 'Add topic' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Transactions is added.' })).toBeVisible();

    // Questions: one line to write one down, the panel for the rest.
    const questions = page.getByRole('list', { name: 'Questions' });
    const line = page.getByPlaceholder("What don't you get? Write it down…");
    await line.fill('What does a foreign key point at?');
    await line.press('Enter');
    await expect(questions).toContainText('What does a foreign key point at?');
    await expect(line).toHaveValue('');

    await page.getByRole('button', { name: 'New question' }).click();
    const panel = page.locator('#question-dialog');
    await expect(panel.getByLabel('Question', { exact: true })).toBeFocused();
    await panel.getByLabel('Question', { exact: true }).fill('When is a table in third normal form?');
    await panel.getByLabel(/^Topic/).selectOption({ label: 'Normalisation' });
    await panel.getByText('Stuck', { exact: true }).click();
    await panel.getByRole('button', { name: 'Save' }).click();
    await expect(panel).toBeHidden();
    // Stuck comes first.
    await expect(questions.getByRole('listitem').first()).toContainText('When is a table in third normal form?');
    await expect(questions.getByRole('listitem').first()).toContainText('Stuck · Normalisation');

    await page.getByRole('button', { name: /^Stuck\s+1$/ }).click();
    await expect(questions.getByRole('listitem')).toHaveCount(1);
    await page.getByRole('button', { name: /^All\s+3$/ }).click();

    await questions.getByRole('button', { name: 'When is a table in third normal form?', exact: true }).click();
    await panel.getByText('Answered', { exact: true }).click();
    await panel.getByRole('textbox', { name: /^Answer/ }).fill('When no column depends on another non-key column.');
    await panel.getByRole('button', { name: 'Save' }).click();
    await expect(questions).toContainText('When no column depends on another non-key column.');
});

for (const [name, viewport] of Object.entries({ desktop, phone })) {
    test(`every colour in Progress comes from a token: ${name}`, async ({ page }) => {
        await page.setViewportSize(viewport);
        await openProgress(page);
        await useSentinelTheme(page);
        const states = { progress: await foreignColours(page) };
        await page.getByRole('button', { name: 'New question' }).click();
        await page.locator('#question-dialog').getByLabel('Question', { exact: true }).waitFor();
        states['question panel'] = await foreignColours(page);
        for (const [state, colours] of Object.entries(states)) {
            expect(colours, `${state}: colours not from a token`).toEqual([]);
        }
    });
}

for (const theme of THEMES) {
    test(`axe finds no violations in Progress: ${theme}`, async ({ page }) => {
        await page.setViewportSize(desktop);
        await openProgress(page);
        await useTheme(page, theme);
        expect(await analyse(page)).toEqual([]);
        await page.getByRole('button', { name: 'New topic' }).click();
        await dialog(page).getByLabel('Name').waitFor();
        expect(await analyse(page)).toEqual([]);
    });
}

test('Progress never scrolls sideways at 320 px, even with 200% text', async ({ page }) => {
    await openProgress(page);
    await page.setViewportSize({ width: 320, height: 800 });
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
});
