import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { foreignColours, makeStudentWithSession, openStudentHome, THEMES, useSentinelTheme, useTheme } from './support.js';

/* Questions in a study session and on a module's page (docs/specs/study-memory.md §3). */

const desktop = { width: 1440, height: 900 };
const phone = { width: 390, height: 844 };
const analyse = async (page) => (await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze())
    .violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target).join(' ')}`);
const panel = (page) => page.locator('#question-dialog');

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

async function ask(page, text) {
    await page.getByRole('button', { name: 'Ask a question', exact: true }).click();
    await panel(page).getByLabel('Question', { exact: true }).fill(text);
    await panel(page).getByRole('button', { name: 'Save' }).click();
    await panel(page).waitFor({ state: 'hidden' });
    await expect(page.getByRole('list', { name: 'Questions' })).toContainText(text);
}

async function withQuestions(page) {
    const student = await openSession(page);
    await ask(page, 'Why does a left join keep rows with no match?');
    await ask(page, 'What is the difference between a key and an index?');
    await expect(page.getByRole('button', { name: 'Ask a question', exact: true })).toHaveAccessibleDescription('3 questions open');
    return student;
}

test('a question written in a session is in its module, and gets answered there', async ({ page }) => {
    await page.setViewportSize(desktop);
    const student = await withQuestions(page);
    const questions = page.getByRole('list', { name: 'Questions' });
    await page.getByRole('button', { name: 'Actions for What is the difference between a key and an index?' }).click();
    await page.locator('.row-menu:not([hidden])').getByRole('button', { name: 'Stuck' }).click();
    await expect(questions.getByRole('listitem').first()).toContainText('Stuck');

    await page.goto(`/workspaces/${student.workspace}/modules`);
    await page.locator('main').getByRole('link', { name: 'Week 1: Relational model' }).click();
    await page.getByRole('heading', { level: 1, name: 'Week 1: Relational model' }).waitFor();
    const onModule = page.getByRole('list', { name: 'Questions' });
    await expect(onModule).toContainText('Why does a left join keep rows with no match?');

    await page.getByRole('button', { name: 'Actions for Why does a left join keep rows with no match?' }).click();
    await page.locator('.row-menu:not([hidden])').getByRole('button', { name: 'Answered' }).click();
    await expect(panel(page).getByLabel('Answered')).toBeChecked();
    await panel(page).getByRole('textbox', { name: /^Answer/ }).fill('A LEFT JOIN keeps every left row and fills the rest with NULL.');
    await panel(page).getByRole('button', { name: 'Save' }).click();
    await expect(panel(page)).toBeHidden();
    await expect(onModule).toContainText('A LEFT JOIN keeps every left row and fills the rest with NULL.');
    await expect(page.getByRole('button', { name: /^Answered\s+1$/ })).toBeVisible();
});

for (const [name, viewport] of Object.entries({ desktop, phone })) {
    test(`every colour of questions comes from a token: ${name}`, async ({ page }) => {
        await page.setViewportSize(viewport);
        await withQuestions(page);
        await useSentinelTheme(page);
        const states = { board: await foreignColours(page) };
        await page.getByRole('list', { name: 'Questions' }).getByRole('button', { name: 'Why does a left join keep rows with no match?', exact: true }).click();
        await panel(page).getByText('Stuck', { exact: true }).click();
        states.panel = await foreignColours(page);
        for (const [state, colours] of Object.entries(states)) {
            expect(colours, `${state}: colours not from a token`).toEqual([]);
        }
    });
}

for (const theme of THEMES) {
    test(`axe finds no violations in questions: ${theme}`, async ({ page }) => {
        await page.setViewportSize(desktop);
        await withQuestions(page);
        await useTheme(page, theme);
        expect(await analyse(page), 'board').toEqual([]);
        await page.getByRole('list', { name: 'Questions' }).getByRole('button', { name: 'Why does a left join keep rows with no match?', exact: true }).click();
        await panel(page).getByText('Answered', { exact: true }).click();
        expect(await analyse(page), 'panel').toEqual([]);
    });
}

test('questions never scroll sideways at 320 px, even with 200% text', async ({ page }) => {
    await withQuestions(page);
    await page.setViewportSize({ width: 320, height: 800 });
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
    await page.getByRole('list', { name: 'Questions' }).getByRole('button', { name: 'Why does a left join keep rows with no match?', exact: true }).click();
    await panel(page).getByLabel('Question', { exact: true }).waitFor();
    expect(await panel(page).evaluate((el) => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
});

test("a module page's New adds a question, in the side panel", async ({ page }) => {
    await page.setViewportSize(desktop);
    const student = await openSession(page);
    await page.goto(`/workspaces/${student.workspace}/modules`);
    await page.locator('main').getByRole('link', { name: 'Week 1: Relational model' }).click();
    await page.getByRole('heading', { level: 1, name: 'Week 1: Relational model' }).waitFor();
    await page.waitForLoadState('load');
    await expect(page.getByPlaceholder("What don't you get? Write it down…")).toHaveCount(0);
    await page.locator('main').getByRole('button', { name: 'New', exact: true }).click();
    await page.locator('#new-menu').getByRole('button', { name: 'Question' }).click();
    await panel(page).getByLabel('Question', { exact: true }).fill('When is a view better than a table?');
    await panel(page).getByRole('button', { name: 'Save' }).click();
    await expect(page.getByRole('list', { name: 'Questions' })).toContainText('When is a view better than a table?');
});
