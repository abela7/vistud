import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { foreignColours, makeStudentWithTopics, newHere, openStudentHome, openTab, THEMES, useSentinelTheme, useTheme } from './support.js';

/* The tracker's step 1b: the Overview, assignments and tasks, instructions and links (docs/specs/study-memory.md §3); findings are kept on the topic sheet (progress.spec.js). */

const desktop = { width: 1440, height: 900 };
const phone = { width: 390, height: 844 };
const analyse = async (page) => (await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze())
    .violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target).join(' ')}`);

test.use({ reducedMotion: 'reduce' });
test.describe.configure({ timeout: 60_000 });

async function open(page, section = '') {
    const student = makeStudentWithTopics();
    await openStudentHome(page, student.email);
    await page.goto(`/workspaces/${student.workspace}${section ? `/${section}` : ''}`);
    await page.getByRole('heading', { level: 1 }).waitFor();
    await page.waitForLoadState('load');
    return student;
}

test('Progress ends with the student\'s rhythm', async ({ page }) => {
    await page.setViewportSize(desktop);
    await open(page, 'progress');
    await expect(page.locator('.stat-row')).toContainText('Streak');
    await expect(page.locator('.stat-row')).toContainText('Last 7 days');
    await expect(page.locator('.stat-row')).toContainText('Cards to review');
});

test('the Overview is short: what is next, what to continue, what\'s coming up', async ({ page }) => {
    await page.setViewportSize(desktop);
    await open(page);
    await expect(page.getByText('Next:')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Lecture 3: joins' })).toBeVisible();
    // The modules, small: each with where it runs and how many of its topics are understood.
    await expect(page.getByRole('region', { name: /^Modules/ }).getByRole('link', { name: 'Week 1: Relational model' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'All modules' })).toBeVisible();
    // Topics live in Progress; the home names only the one to do next, in its Next line.
    await expect(page.getByText('Primary and foreign keys')).toHaveCount(1);
    await expect(page.locator('.next-line')).toContainText('Primary and foreign keys');

    const todo = page.getByRole('group', { name: 'To do' });
    await expect(todo.getByRole('listitem')).toHaveCount(3);
    await expect(todo.getByRole('listitem').first()).toContainText('SQL lab 2');
    await expect(todo.getByRole('listitem').first()).toContainText('In progress');

    await todo.getByRole('button', { name: 'Done: SQL lab 2' }).click();
    await expect(todo.getByRole('listitem')).toHaveCount(2);
    await page.getByRole('button', { name: 'Show 1 done' }).click();
    await expect(page.getByRole('list', { name: 'Done' }).getByRole('button', { name: 'Done: SQL lab 2' })).toHaveAttribute('aria-pressed', 'true');

    await page.getByRole('button', { name: 'Add a task' }).click();
    const dialog = page.locator('#tasks-dialog');
    await expect(dialog.getByLabel('What')).toBeFocused();
    await dialog.getByLabel('What').fill('Normalisation problem sheet');
    await dialog.getByLabel('Kind').selectOption({ label: 'Problem set' });
    await dialog.getByRole('button', { name: 'Add' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Normalisation problem sheet is added.' })).toBeVisible();

    // The instructions for the AI are one dialog, from the page's menu.
    await page.getByRole('button', { name: 'More for Databases' }).click();
    await page.getByRole('button', { name: 'Instructions for the AI' }).click();
    const instructions = page.locator('#instructions-dialog');
    await expect(instructions.getByLabel('This course')).toHaveValue('Go slide by slide. After each section, ask me two questions before moving on.');
    await instructions.getByLabel(/About you/).fill('Second-year student. Use everyday examples.');
    await instructions.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Instructions saved.' })).toBeVisible();
});

test('a web link sits in its module and opens in a new tab', async ({ page }) => {
    await page.setViewportSize(desktop);
    await open(page, 'modules');
    await page.locator('main').getByRole('link', { name: 'Week 1: Relational model' }).click();
    await page.getByRole('heading', { level: 1, name: 'Week 1: Relational model' }).waitFor();
    await page.waitForLoadState('load');
    await openTab(page, 'Files');
    const link = page.getByRole('link', { name: /Joins explained \(video\)/ });
    await expect(link).toHaveAttribute('target', '_blank');
    await expect(link).toHaveAttribute('rel', 'noopener noreferrer');

    await newHere(page, 'Link');
    const dialog = page.locator('#structure-dialog');
    await expect(dialog.getByLabel('Address')).toBeFocused();
    await dialog.getByLabel('Address').fill('javascript:alert(1)');
    await dialog.getByRole('button', { name: 'Add link' }).click();
    await expect(dialog).toContainText('Enter a web address');
    await dialog.getByLabel('Address').fill('en.wikipedia.org/wiki/Join_(SQL)');
    await dialog.getByRole('button', { name: 'Add link' }).click();
    await expect(page.getByRole('link', { name: /en\.wikipedia\.org/ })).toBeVisible();
});

const screens = {
    overview: async (page) => {
        await open(page);
        await useSentinelTheme(page);
        const states = { overview: await foreignColours(page) };
        await page.getByRole('button', { name: 'Add a task' }).click();
        await page.locator('#tasks-dialog').getByLabel('What').waitFor();
        states['task dialog'] = await foreignColours(page);
        return states;
    },
};

for (const [name, viewport] of Object.entries({ desktop, phone })) {
    for (const [screen, run] of Object.entries(screens)) {
        test(`every colour in the ${screen} comes from a token: ${name}`, async ({ page }) => {
            await page.setViewportSize(viewport);
            const states = await run(page);
            for (const [state, colours] of Object.entries(states)) {
                expect(colours, `${state}: colours not from a token`).toEqual([]);
            }
        });
    }
}

for (const theme of THEMES) {
    test(`axe finds no violations in the Overview and its dialogs: ${theme}`, async ({ page }) => {
        await page.setViewportSize(desktop);
        await open(page);
        await useTheme(page, theme);
        expect(await analyse(page)).toEqual([]);
        await page.getByRole('button', { name: 'More for Databases' }).click();
        await page.getByRole('button', { name: 'Instructions for the AI' }).click();
        await page.locator('#instructions-dialog').getByLabel('This course').waitFor();
        expect(await analyse(page)).toEqual([]);

    });
}

test('the Overview never scrolls sideways at 320 px, even with 200% text', async ({ page }) => {
    await open(page);
    await page.setViewportSize({ width: 320, height: 800 });
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
});
