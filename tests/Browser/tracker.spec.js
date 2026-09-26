import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { foreignColours, makeStudentWithTopics, openStudentHome, THEMES, useSentinelTheme, useTheme } from './support.js';

/* The tracker's step 1b: the Overview, assignments and tasks, instructions, findings and links (docs/specs/study-memory.md §3). */

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

test('the Overview says where the student is, and tasks are ticked off there', async ({ page }) => {
    await page.setViewportSize(desktop);
    await open(page);
    await expect(page.getByRole('list', { name: 'Topics by status' }).getByRole('listitem')).toHaveText(['1 not started', '0 covered', '1 understood', '1 confused', '0 mastered']);
    await expect(page.getByRole('region', { name: 'Where you are' })).toContainText('Primary and foreign keys');
    await expect(page.getByRole('region', { name: 'Where you are' })).toContainText('Why does a left join keep the unmatched rows?');
    await expect(page.getByRole('link', { name: 'Lecture 3: joins' })).toBeVisible();

    const todo = page.getByRole('list', { name: 'To do' });
    await expect(todo.getByRole('listitem')).toHaveCount(3);
    await expect(todo.getByRole('listitem').first()).toContainText('SQL lab 2');
    await expect(todo.getByRole('listitem').first()).toContainText('In progress');

    await todo.getByRole('button', { name: 'Done: SQL lab 2' }).click();
    await expect(page.getByRole('heading', { name: /Assignments and tasks/ })).toContainText('(2 to do)');
    await page.getByRole('button', { name: 'Show 1 done' }).click();
    await expect(page.getByRole('list', { name: 'Done' }).getByRole('button', { name: 'Done: SQL lab 2' })).toHaveAttribute('aria-pressed', 'true');

    await page.getByRole('button', { name: 'New', exact: true }).click();
    const dialog = page.locator('#tasks-dialog');
    await expect(dialog.getByLabel('What')).toBeFocused();
    await dialog.getByLabel('What').fill('Normalisation problem sheet');
    await dialog.getByLabel('Kind').selectOption({ label: 'Problem set' });
    await dialog.getByRole('button', { name: 'Add' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Normalisation problem sheet is added.' })).toBeVisible();

    await page.getByRole('button', { name: 'Edit: About you' }).click();
    await page.locator('#instructions-dialog').getByRole('textbox').fill('Second-year student. Use everyday examples.');
    await page.locator('#instructions-dialog').getByRole('button', { name: 'Save' }).click();
    await expect(page.getByRole('region', { name: 'Instructions for the assistant' })).toContainText('Second-year student. Use everyday examples.');
});

test('findings open under their topic and are added with their source', async ({ page }) => {
    await page.setViewportSize(desktop);
    await open(page, 'progress');
    const joins = page.locator('.topic-row').filter({ has: page.getByText('Joins', { exact: true }) });
    await joins.getByRole('button', { name: '2 findings' }).click();
    const list = page.getByRole('list', { name: 'Findings about Joins' });
    await expect(list).toContainText('A left join keeps every row of the left table, matched or not.');
    await expect(list).toContainText('From Lecture 3: joins, slide 12');
    await expect(list).toContainText('From a study session');
    await expect(joins.getByRole('button', { name: '2 findings' })).toHaveAttribute('aria-expanded', 'true');

    await page.getByRole('button', { name: 'Add a finding' }).click();
    const dialog = page.locator('#progress-dialog');
    await expect(dialog.getByLabel('What you need to know')).toBeFocused();
    await dialog.getByLabel('What you need to know').fill('A right join is a left join the other way round.');
    await dialog.getByLabel('From (optional)').selectOption({ label: 'Lecture 3: joins' });
    await dialog.getByLabel('Where in it (optional)').fill('slide 14');
    await dialog.getByRole('button', { name: 'Add finding' }).click();
    await expect(list).toContainText('A right join is a left join the other way round.');
    await expect(joins.getByRole('button', { name: '3 findings' })).toBeVisible();
});

test('a web link sits in its module and opens in a new tab', async ({ page }) => {
    await page.setViewportSize(desktop);
    await open(page, 'modules');
    const link = page.getByRole('link', { name: /Joins explained \(video\)/ });
    await expect(link).toHaveAttribute('target', '_blank');
    await expect(link).toHaveAttribute('rel', 'noopener noreferrer');

    await page.getByRole('button', { name: 'Add link' }).click();
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
        await page.getByRole('button', { name: 'New', exact: true }).click();
        await page.locator('#tasks-dialog').getByLabel('What').waitFor();
        states['task dialog'] = await foreignColours(page);
        return states;
    },
    progress: async (page) => {
        await open(page, 'progress');
        await useSentinelTheme(page);
        await page.getByRole('button', { name: '2 findings' }).click();
        await page.getByRole('list', { name: 'Findings about Joins' }).waitFor();
        return { 'progress with findings': await foreignColours(page) };
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
    test(`axe finds no violations in the Overview and findings: ${theme}`, async ({ page }) => {
        await page.setViewportSize(desktop);
        const student = await open(page);
        await useTheme(page, theme);
        expect(await analyse(page)).toEqual([]);
        await page.getByRole('button', { name: 'Edit: This course' }).click();
        await page.locator('#instructions-dialog').getByRole('textbox').waitFor();
        expect(await analyse(page)).toEqual([]);

        await page.goto(`/workspaces/${student.workspace}/progress`);
        await page.getByRole('button', { name: '2 findings' }).click();
        await page.getByRole('list', { name: 'Findings about Joins' }).waitFor();
        await useTheme(page, theme);
        expect(await analyse(page)).toEqual([]);
    });
}

test('the Overview never scrolls sideways at 320 px, even with 200% text', async ({ page }) => {
    await open(page);
    await page.setViewportSize({ width: 320, height: 800 });
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
});
