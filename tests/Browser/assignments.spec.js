import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { fileURLToPath } from 'node:url';
import { makeStudentWithModules, openStudentHome } from './support.js';

/* Assignments: a name, a deadline and its files (the owner's review, 2026-10-02). */

const fixture = (name) => fileURLToPath(new URL(`./fixtures/files/${name}`, import.meta.url));
const devices = {
    computer: { viewport: { width: 1440, height: 900 } },
    phone: { viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true },
};

test.use({ reducedMotion: 'reduce' });

/** Biology's Assignments section, reached the way a student would. */
async function openAssignments(page, onPhone) {
    await openStudentHome(page, makeStudentWithModules());
    await page.locator('main').getByRole('link', { name: 'Biology' }).click();
    await page.getByRole('heading', { level: 1, name: 'Biology' }).waitFor();
    const nav = onPhone ? page.locator('.app-tabbar') : page.locator('.app-sidebar');
    await nav.getByRole('link', { name: onPhone ? 'Tasks' : 'Assignments' }).click();
    await page.getByRole('heading', { level: 1, name: 'Assignments' }).waitFor();
}

for (const [name, device] of Object.entries(devices)) {
    test.describe(name, () => {
        test.use(device);

        test(`an assignment is made with its name, deadline and files, and tracked (${name})`, async ({ page }) => {
            const onPhone = name === 'phone';
            await openAssignments(page, onPhone);
            await expect(page.getByText('No assignments yet')).toBeVisible();
            await page.locator('main').getByRole('link', { name: 'New assignment' }).first().click();
            await page.getByRole('heading', { level: 1, name: 'New assignment' }).waitFor();

            await page.getByLabel('Name').fill('Coursework 1: cell report');
            await page.getByLabel('Module').selectOption({ label: 'Week 1: Cells' });
            await page.getByLabel('Day').fill('2030-03-14');
            await page.getByLabel('Time (optional)').fill('23:59');
            // Files chosen before it exists wait, and go up once it is created.
            await page.locator('input[data-upload-files]').setInputFiles([fixture('Lecture 2 - cell division.pdf'), fixture('Onion cells.png')]);
            await expect(page.getByRole('list', { name: 'Chosen files' }).getByText('Ready to add')).toHaveCount(2);
            await page.getByRole('button', { name: 'Create' }).click();

            await expect(page).toHaveURL(/\/assignments\/[0-9a-f-]+$/);
            await expect(page.getByRole('heading', { level: 1, name: 'Coursework 1: cell report' })).toBeVisible();
            await expect(page.getByText('2 files added.')).toBeVisible();
            const files = page.locator('.assignment-files');
            await expect(files.getByRole('link', { name: 'Lecture 2 - cell division.pdf' })).toBeVisible();
            await expect(files.getByRole('link', { name: 'Onion cells.png' })).toBeVisible();
            await expect(page.locator('.assignment-deadline')).toContainText('Due 14 Mar 2030, 23:59');

            // Where the student is with it.
            await page.locator('.status-choice').filter({ hasText: 'In progress' }).click();
            await expect(page.getByText('In progress.', { exact: true })).toBeVisible();

            // Nothing on the page scrolls sideways.
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1)).toBe(true);

            // On the list: its card, with its files; its folder is in its module with them.
            await page.getByRole('link', { name: 'Back to Assignments' }).click();
            const card = page.locator('.question-card').filter({ hasText: 'Coursework 1: cell report' });
            await expect(card).toContainText('2 files');
            await expect(card).toContainText('In progress');
            await card.getByRole('link', { name: 'Coursework 1: cell report' }).click();
            await page.getByRole('link', { name: 'Open its folder' }).click();
            await expect(page.getByRole('heading', { level: 1, name: 'Coursework 1: cell report' })).toBeVisible();
            await expect(page.locator('main').getByRole('link', { name: 'Onion cells.png' })).toBeVisible();
        });
    });
}


for (const [name, device] of Object.entries(devices)) {
    test.describe(`plan on a ${name}`, () => {
        test.use(device);

        test(`an assignment's plan is made, ticked, checked and changed (${name})`, async ({ page }) => {
            const onPhone = name === 'phone';
            await openAssignments(page, onPhone);
            await page.locator('main').getByRole('link', { name: 'New assignment' }).first().click();
            await page.getByLabel('Name').fill('Coursework 2: osmosis report');
            await page.getByLabel('Day').fill('2030-03-14');
            await page.getByRole('button', { name: 'Create' }).click();
            await page.getByRole('heading', { level: 1, name: 'Coursework 2: osmosis report' }).waitFor();

            // Starters first: pick the nearest and change what doesn't fit.
            await expect(page.getByText('How do you want to start?')).toBeVisible();
            await page.getByRole('button', { name: /Essay or report/ }).click();
            await expect(page.getByRole('heading', { level: 3, name: 'Research' })).toBeVisible();
            await expect(page.locator('.plan-percent')).toHaveText('0%');
            await expect(page.getByRole('group', { name: /Use of sources/ })).toBeVisible();

            // Ticking the first step starts the assignment and moves the bar.
            await page.getByRole('button', { name: 'Done: Find and read the sources' }).click();
            await expect(page.getByText('1 of 12 done')).toBeVisible();
            await expect(page.locator('.section-header').getByText('In progress')).toBeVisible();
            await expect(page.locator('.plan-pace')).toContainText('left');

            // A step added to a part, a criterion checked, a step renamed.
            await page.getByLabel('Add a step to Research').fill('Check the library catalogue');
            await page.getByLabel('Add a step to Research').press('Enter');
            await expect(page.getByText('Check the library catalogue', { exact: true })).toBeVisible();
            await expect(page.getByText('1 of 13 done')).toBeVisible();
            await page.getByRole('group', { name: /Use of sources/ }).getByRole('button', { name: 'Partly' }).click();
            await expect(page.getByRole('group', { name: /Use of sources/ }).getByRole('button', { name: 'Partly' })).toHaveAttribute('aria-pressed', 'true');
            await page.getByRole('button', { name: 'Actions for Note the key points' }).click();
            await page.locator('.row-menu:popover-open').getByRole('button', { name: 'Edit' }).click();
            await page.locator('#plan-edit-title').fill('Note the key points and quotes');
            await page.locator('#plan-edit-title').press('Enter');
            await expect(page.getByText('Note the key points and quotes', { exact: true })).toBeVisible();

            // It is all still there after a reload, and nothing scrolls sideways.
            await page.reload();
            await expect(page.getByText('1 of 13 done')).toBeVisible();
            await expect(page.getByRole('group', { name: /Use of sources/ }).getByRole('button', { name: 'Partly' })).toHaveAttribute('aria-pressed', 'true');
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1)).toBe(true);

            // With an AI: the brief goes into the prompt, the reply is read, looked over and added.
            await page.getByRole('button', { name: 'Plan it with an AI' }).first().click();
            await page.getByLabel(/The assignment brief/).fill('Explain osmosis in 1500 words. Marked on accuracy (50%) and clarity (50%).');
            await expect(page.getByLabel('The prompt', { exact: true })).toContainText('Explain osmosis in 1500 words.');
            await page.getByLabel("The AI's reply").fill('Here you go:\n<part title="Lab write-up" marks="50"><step>Write the method</step></part><criterion marks="50">Accuracy</criterion>');
            await page.getByRole('button', { name: 'Read the reply' }).click();
            await expect(page.getByText('Look it over, then add it')).toBeVisible();
            await page.getByRole('button', { name: 'Add to my plan' }).click();
            await expect(page.getByRole('heading', { level: 3, name: 'Lab write-up' })).toBeVisible();
            await expect(page.getByText('3 things added to your plan.')).toBeVisible();

            // Its progress is on the card in the list.
            await page.getByRole('link', { name: 'Back to Assignments' }).click();
            const card = page.locator('.question-card').filter({ hasText: 'Coursework 2: osmosis report' });
            await expect(card).toContainText('1 of 14 done');
            await expect(card).toContainText('In progress');
        });
    });
}

test('axe finds no violations on the assignment pages', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await openAssignments(page, false);
    await page.locator('main').getByRole('link', { name: 'New assignment' }).first().click();
    await page.getByRole('heading', { level: 1, name: 'New assignment' }).waitFor();
    const analyse = async () => (await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze())
        .violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target).join(' ')}`);
    expect(await analyse()).toEqual([]);
    await page.getByLabel('Name').fill('Lab report 3');
    await page.getByLabel('Day').fill('2030-03-14');
    await page.getByRole('button', { name: 'Create' }).click();
    await page.getByRole('heading', { level: 1, name: 'Lab report 3' }).waitFor();
    expect(await analyse()).toEqual([]);
    await page.getByRole('button', { name: /Lab report/ }).click();
    await page.getByRole('heading', { level: 3, name: 'Data' }).waitFor();
    expect(await analyse()).toEqual([]);
    await page.getByRole('button', { name: 'Plan it with an AI' }).first().click();
    await page.getByLabel('The prompt', { exact: true }).waitFor();
    expect(await analyse()).toEqual([]);
    await page.getByRole('link', { name: 'Back to Assignments' }).click();
    await page.getByRole('heading', { level: 1, name: 'Assignments' }).waitFor();
    expect(await analyse()).toEqual([]);
});

test('the plan never scrolls sideways at 320 px, even with 200% text', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 640 });
    await openAssignments(page, true);
    await page.locator('main').getByRole('link', { name: 'New assignment' }).first().click();
    await page.getByLabel('Name').fill('Lab report with a rather long name that goes on and on');
    await page.getByLabel('Day').fill('2030-03-14');
    await page.getByRole('button', { name: 'Create' }).click();
    await page.getByRole('heading', { level: 1, name: /Lab report with a rather long name/ }).waitFor();
    await page.getByRole('button', { name: /Essay or report/ }).click();
    await page.getByRole('heading', { level: 3, name: 'Research' }).waitFor();
    await page.getByRole('button', { name: 'Plan it with an AI' }).first().click();
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
});
