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
    await page.getByRole('link', { name: 'Back to Assignments' }).click();
    await page.getByRole('heading', { level: 1, name: 'Assignments' }).waitFor();
    expect(await analyse()).toEqual([]);
});
