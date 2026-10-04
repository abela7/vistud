import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { foreignColours, makeStudentWithModules, openStudentHome, THEMES, useSentinelTheme, useTheme } from './support.js';

/*
 * Setting a course up and the course home (docs/specs/vistud-2-blueprint.md §3.5.1, §3.5.2): a new course opens its
 * setup sheet (what is this course, how do you like to learn, each skippable) and lands on a home that says what to
 * do next; with modules, the home shows the ring, the modules around where the student is, and Study.
 */

const sizes = { desktop: { width: 1440, height: 900 }, phone: { width: 390, height: 844 } };
const sheet = (page) => page.locator('#course-setup');
const analyse = async (page) =>
    (await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze()).violations.map(
        (v) => `${v.id}: ${v.nodes.map((n) => n.target).join(' ')}`,
    );
const noSidewaysScroll = (page) => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth);

test.use({ reducedMotion: 'reduce' });
test.describe.configure({ timeout: 90_000 });

async function createCourse(page, name) {
    await page.getByRole('button', { name: 'New course' }).first().click();
    await page.locator('#workspace-form').getByLabel('Name').fill(name);
    await page.locator('#workspace-form').getByRole('button', { name: 'Create course' }).click();
    await expect(sheet(page)).toBeVisible();
}

async function openBiology(page) {
    await openStudentHome(page, makeStudentWithModules());
    await page.getByRole('link', { name: 'Biology' }).first().click();
    await expect(page.getByRole('heading', { level: 1, name: 'Biology', exact: true })).toBeVisible();
}

for (const [name, viewport] of Object.entries(sizes)) {
    test(`a new course goes straight to its setup, each step can be skipped, and the home says what is next: ${name}`, async ({ page }) => {
        await page.setViewportSize(viewport);
        await openStudentHome(page);
        await createCourse(page, 'Operating Systems');

        // Step 2 of 3: what is this course? Writing it by hand is always possible.
        await expect(sheet(page).getByText('Step 2 of 3')).toBeVisible();
        await expect(sheet(page).getByRole('heading', { name: 'What is this course?' })).toBeVisible();
        await sheet(page).getByRole('button', { name: 'Write it myself' }).click();
        await expect(sheet(page).getByText('How it is assessed')).toBeVisible();
        await sheet(page).getByLabel('About', { exact: true }).fill('Processes, memory and file systems.');
        await sheet(page).getByRole('button', { name: 'Add an item' }).click();
        await sheet(page).getByPlaceholder('Midterm').fill('Midterm');
        await sheet(page).getByRole('button', { name: 'Skip' }).click();

        // Step 3 of 3: how do you like to learn? Four short questions.
        await expect(sheet(page).getByText('Step 3 of 3')).toBeVisible();
        await expect(sheet(page).getByRole('heading', { name: 'How do you like to learn?' })).toBeVisible();
        await sheet(page).getByText('Examples first', { exact: true }).click();
        await sheet(page).getByText('Small steps', { exact: true }).click();
        await sheet(page).getByText('Often', { exact: true }).click();
        await sheet(page).getByText('Top marks', { exact: true }).click();
        expect(await noSidewaysScroll(page)).toBe(true);
        await sheet(page).getByRole('button', { name: 'Done' }).click();

        // The course home: one line of what to do next, first.
        await expect(page.getByRole('heading', { level: 1, name: 'Operating Systems', exact: true })).toBeVisible();
        await expect(sheet(page)).toBeHidden();
        await expect(page.getByText('Add your first module')).toBeVisible();
        expect(await noSidewaysScroll(page)).toBe(true);

        // What was chosen is there when the sheet is opened again from the menu.
        await page.getByRole('button', { name: 'More for Operating Systems' }).click();
        await page.getByRole('button', { name: 'How you learn' }).click();
        await expect(sheet(page).getByLabel('Small steps')).toBeChecked();
        await expect(sheet(page).getByLabel('Examples first')).toBeChecked();
        await page.keyboard.press('Escape');
    });

    test(`the course home shows the next step, the modules and Study, and Study starts a session with no dialog: ${name}`, async ({ page }) => {
        await page.setViewportSize(viewport);
        await openBiology(page);

        await expect(page.getByText("Add Module 1's files")).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Modules' })).toBeVisible();
        await expect(page.getByRole('link', { name: /Week 1: Cells/ })).toBeVisible();
        await expect(page.getByRole('link', { name: /Week 2: Cell division/ })).toBeVisible();
        expect(await noSidewaysScroll(page)).toBe(true);

        await page.getByRole('button', { name: 'More for Biology' }).click();
        for (const item of ['Edit course', 'About this course', 'How you learn', 'Instructions for the AI', 'Study with options', 'AI settings', 'Log time']) {
            await expect(page.getByRole('menu').or(page.locator('#page-menu')).getByText(item, { exact: true })).toBeVisible();
        }
        await page.keyboard.press('Escape');

        // Study: no dialog, straight to a session in the module the student is in.
        await page.getByRole('button', { name: 'Study', exact: true }).first().click();
        await page.waitForURL(/\/sessions\//);
    });

    test(`every colour on the setup sheet and the course home comes from a token: ${name}`, async ({ page }) => {
        await page.setViewportSize(viewport);
        await openBiology(page);
        await useSentinelTheme(page);
        const states = { home: await foreignColours(page) };

        await page.getByRole('button', { name: 'More for Biology' }).click();
        await page.getByRole('button', { name: 'About this course' }).click();
        await expect(sheet(page)).toBeVisible();
        await sheet(page).getByRole('button', { name: 'Write it myself' }).click();
        states['sheet, about'] = await foreignColours(page);
        await page.keyboard.press('Escape');
        await page.getByRole('button', { name: 'More for Biology' }).click();
        await page.getByRole('button', { name: 'How you learn' }).click();
        await expect(sheet(page)).toBeVisible();
        states['sheet, how you learn'] = await foreignColours(page);

        expect(Object.fromEntries(Object.entries(states).filter(([, found]) => found.length > 0)), 'colours not from a token').toEqual({});
    });

    for (const theme of THEMES) {
        test(`axe finds no violations on the course home and the sheet: ${name}, ${theme}`, async ({ page }) => {
            await page.setViewportSize(viewport);
            await openBiology(page);
            await useTheme(page, theme);
            expect(await analyse(page)).toEqual([]);

            await page.getByRole('button', { name: 'More for Biology' }).click();
            await page.getByRole('button', { name: 'About this course' }).click();
            await expect(sheet(page)).toBeVisible();
            await sheet(page).getByRole('button', { name: 'Write it myself' }).click();
            await sheet(page).getByRole('button', { name: 'Add an item' }).click();
            expect(await analyse(page)).toEqual([]);

            await page.keyboard.press('Escape');
            await page.getByRole('button', { name: 'More for Biology' }).click();
            await page.getByRole('button', { name: 'How you learn' }).click();
            await expect(sheet(page).getByRole('heading', { name: 'How do you like to learn?' })).toBeVisible();
            expect(await analyse(page)).toEqual([]);
        });
    }
}
