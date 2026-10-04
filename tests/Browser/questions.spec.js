import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { foreignColours, makeStudentWithSession, newHere, openStudentHome, openTab, THEMES, useSentinelTheme, useTheme } from './support.js';

/* Questions on a module's Questions tab, asked from a session's + menu, and each on a page of its own (docs/specs/study-memory.md §3). */

const desktop = { width: 1440, height: 900 };
const phone = { width: 390, height: 844 };
const analyse = async (page) => (await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze())
    .violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target).join(' ')}`);
const words = (page) => page.getByLabel("What don't you get?");
const answerBox = (page) => page.getByRole('textbox', { name: /^Answer/ });

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

/** The module's Questions tab: the questions of a session are there, not in the session. */
async function openQuestions(page) {
    const student = makeStudentWithSession();
    await openStudentHome(page, student.email);
    await page.goto(`/workspaces/${student.workspace}/modules`);
    await page.locator('main').getByRole('link', { name: 'Week 1: Relational model' }).click();
    await page.getByRole('heading', { level: 1, name: 'Week 1: Relational model' }).waitFor();
    await page.waitForLoadState('load');
    await openTab(page, 'Questions');
    await page.getByRole('list', { name: 'Questions' }).waitFor();
    return student;
}

async function ask(page, text) {
    // A page of its own; saving comes back to the module's questions.
    await page.getByRole('link', { name: 'New question' }).first().click();
    await page.getByRole('heading', { level: 1, name: 'New question' }).waitFor();
    await words(page).fill(text);
    await page.getByRole('button', { name: 'Save' }).click();
    await page.getByRole('list', { name: 'Questions' }).waitFor();
    await expect(page.getByRole('list', { name: 'Questions' })).toContainText(text);
}

async function withQuestions(page) {
    const student = await openQuestions(page);
    await ask(page, 'Why does a left join keep rows with no match?');
    await ask(page, 'What is the difference between a key and an index?');
    await expect(page.getByRole('list', { name: 'Questions' }).getByRole('listitem')).toHaveCount(3);
    return student;
}

test('a question is kept from a session, is on the module\'s Questions tab, and gets answered there', async ({ page }) => {
    await page.setViewportSize(desktop);
    // The session no longer carries a board: the questions are on the module's Questions tab (a session's + menu keeps one there).
    const session = await openSession(page);
    await expect(page.getByRole('list', { name: 'Questions' })).toHaveCount(0);
    await page.goto(`/workspaces/${session.workspace}/modules`);
    await page.locator('main').getByRole('link', { name: 'Week 1: Relational model' }).click();
    await page.getByRole('heading', { level: 1, name: 'Week 1: Relational model' }).waitFor();
    await page.waitForLoadState('load');
    await openTab(page, 'Questions');
    await ask(page, 'Why does a left join keep rows with no match?');
    await ask(page, 'What is the difference between a key and an index?');
    const questions = page.getByRole('list', { name: 'Questions' });
    await page.getByRole('button', { name: 'Actions for What is the difference between a key and an index?' }).click();
    await page.locator('.row-menu:not([hidden])').getByRole('button', { name: 'Stuck' }).click();
    await expect(questions.getByRole('listitem').first()).toContainText('Stuck');
    await page.waitForLoadState('load');
    const onModule = page.getByRole('list', { name: 'Questions' });
    await expect(onModule).toContainText('Why does a left join keep rows with no match?');
    await page.getByLabel('Sort').selectOption({ label: 'Newest first' });
    await expect(onModule.getByRole('listitem').first()).toContainText('What is the difference between a key and an index?');
    await page.getByRole('searchbox', { name: 'Search the questions' }).fill('no match');
    await expect(onModule.getByRole('listitem')).toHaveCount(1);

    // A card's menu opens its page ready to be answered; Save keeps it, and Back is the list.
    await page.getByRole('button', { name: 'Actions for Why does a left join keep rows with no match?' }).click();
    await page.locator('.row-menu:not([hidden])').getByRole('link', { name: 'Answered…' }).click();
    await page.getByRole('heading', { level: 1, name: 'Question' }).waitFor();
    await expect(page.getByLabel('Answered', { exact: true })).toBeChecked();
    await expect(words(page)).toHaveValue('Why does a left join keep rows with no match?');
    await answerBox(page).fill('A LEFT JOIN keeps every left row and fills the rest with NULL.');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'The question is saved.' })).toBeVisible();
    await page.getByRole('link', { name: 'Back to Questions' }).click();
    await page.getByRole('list', { name: 'Questions' }).waitFor();
    await expect(page.getByRole('list', { name: 'Questions' })).toContainText('A LEFT JOIN keeps every left row and fills the rest with NULL.');
    await expect(page.getByRole('button', { name: /^Answered\s+1$/ })).toBeVisible();
});

for (const [name, viewport] of Object.entries({ desktop, phone })) {
    test(`every colour of questions comes from a token: ${name}`, async ({ page }) => {
        await page.setViewportSize(viewport);
        await withQuestions(page);
        await useSentinelTheme(page);
        const states = { 'questions tab': await foreignColours(page) };
        // A page moved to in place brings its own theme: the sentinel goes on again for each.
        await page.getByRole('list', { name: 'Questions' }).getByRole('link', { name: 'Why does a left join keep rows with no match?', exact: true }).click();
        await page.getByRole('heading', { level: 1, name: 'Question' }).waitFor();
        await page.waitForLoadState('load');
        await useSentinelTheme(page);
        await page.getByText('Stuck', { exact: true }).last().click();
        states.question = await foreignColours(page);
        await page.getByRole('link', { name: 'Back to Questions' }).click();
        await page.getByRole('list', { name: 'Questions' }).waitFor();
        await page.waitForLoadState('load');
        await useSentinelTheme(page);
        states.cards = await foreignColours(page);
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
        expect(await analyse(page), 'questions tab').toEqual([]);
        await page.getByRole('list', { name: 'Questions' }).getByRole('link', { name: 'Why does a left join keep rows with no match?', exact: true }).click();
        await page.getByRole('heading', { level: 1, name: 'Question' }).waitFor();
        await page.waitForLoadState('load');
        await useTheme(page, theme);
        expect(await analyse(page), 'question page').toEqual([]);
        await page.getByRole('link', { name: 'Back to Questions' }).click();
        await page.getByRole('list', { name: 'Questions' }).waitFor();
        await page.waitForLoadState('load');
        await useTheme(page, theme);
        expect(await analyse(page), 'question cards').toEqual([]);
    });
}

test('questions never scroll sideways at 320 px, even with 200% text', async ({ page }) => {
    await withQuestions(page);
    await page.setViewportSize({ width: 320, height: 800 });
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
    await page.getByRole('list', { name: 'Questions' }).getByRole('link', { name: 'Why does a left join keep rows with no match?', exact: true }).click();
    await words(page).waitFor();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
    await page.getByRole('link', { name: 'Back to Questions' }).click();
    await page.getByRole('list', { name: 'Questions' }).waitFor();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
});

test("a module page's New adds a question, on the module's questions page", async ({ page }) => {
    await page.setViewportSize(desktop);
    const student = await openSession(page);
    await page.goto(`/workspaces/${student.workspace}/modules`);
    await page.locator('main').getByRole('link', { name: 'Week 1: Relational model' }).click();
    await page.getByRole('heading', { level: 1, name: 'Week 1: Relational model' }).waitFor();
    await page.waitForLoadState('load');
    await expect(page.getByPlaceholder("What don't you get? Write it down…")).toHaveCount(0);
    await newHere(page, 'Question');
    await page.getByRole('heading', { level: 1, name: 'New question' }).waitFor();
    await words(page).fill('When is a view better than a table?');
    await page.getByRole('button', { name: 'Save' }).click();
    // Saved, it is in the module's questions.
    await page.getByRole('list', { name: 'Questions' }).waitFor();
    await expect(page.getByRole('list', { name: 'Questions' })).toContainText('When is a view better than a table?');
});

test('the questions page fills the width: cards in rows, no narrow column', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    const student = await withQuestions(page);
    await page.goto(`/workspaces/${student.workspace}/modules`);
    await page.locator('main').getByRole('link', { name: 'Week 1: Relational model' }).click();
    await page.getByRole('navigation', { name: 'This module' }).getByRole('link', { name: /^Questions/ }).click();
    await page.getByRole('list', { name: 'Questions' }).waitFor();
    await page.waitForLoadState('load');
    const main = await page.locator('#main').boundingBox();
    const cards = page.getByRole('list', { name: 'Questions' }).getByRole('listitem');
    await expect(cards).toHaveCount(3);
    // Three across, side by side, from one edge of the page to the other.
    const boxes = await Promise.all([0, 1, 2].map((i) => cards.nth(i).boundingBox()));
    expect(new Set(boxes.map((b) => Math.round(b.y))).size).toBe(1);
    expect(boxes[2].x + boxes[2].width).toBeGreaterThan(main.x + main.width - 60);
    // The heading holds the actions: New question opens a page of its own.
    await page.getByRole('link', { name: 'New question' }).click();
    await page.getByRole('heading', { level: 1, name: 'New question' }).waitFor();
});
