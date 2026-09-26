import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { foreignColours, makeStudentWithWorkspaces, markPage, openStudentHome, THEMES, useSentinelTheme, useTheme, wasReloaded } from './support.js';

/* Workspaces (docs/specs/workspaces.md, M2 step 1): create, open, switch, edit, archive and restore. */

const desktop = { width: 1440, height: 900 };
const phone = { width: 390, height: 844 };
const form = (page) => page.locator('#workspace-form');
const heading = (page, name) => page.getByRole('heading', { level: 1, name, exact: true });
const analyse = async (page) =>
    (await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze()).violations.map(
        (v) => `${v.id}: ${v.nodes.map((n) => n.target).join(' ')}`,
    );

test.use({ reducedMotion: 'reduce' });
test.describe.configure({ timeout: 60_000 });

async function openForm(page) {
    await page.getByRole('button', { name: 'New workspace' }).first().click();
    await expect(form(page)).toBeVisible();
}

test('a student creates their first workspace, opens it, and creates a second from the switcher', async ({ page }) => {
    await page.setViewportSize(desktop);
    await openStudentHome(page);
    await expect(page.getByText('Create your first workspace')).toBeVisible();

    await openForm(page);
    await expect(form(page).getByLabel('Name')).toBeFocused();
    await form(page).getByRole('button', { name: 'Create workspace' }).click();
    await expect(form(page).getByText('Give the workspace a name.')).toBeVisible();

    await form(page).getByLabel('Name').fill('Biology');
    await form(page).getByText('Green', { exact: true }).click({ force: true });
    await form(page).getByText('Microscope', { exact: true }).click({ force: true });
    await form(page).getByLabel('Course code (optional)').fill('BIO101');
    await form(page).getByRole('button', { name: 'Create workspace' }).click();

    await expect(heading(page, 'Biology')).toBeVisible();
    await expect(page.locator('main dd').getByText('BIO101', { exact: true })).toBeVisible();
    const sidebar = page.locator('.app-sidebar');
    await expect(sidebar.getByRole('link', { name: 'Overview' })).toHaveAttribute('aria-current', 'page');

    await sidebar.getByRole('link', { name: 'Modules' }).click();
    await expect(heading(page, 'Modules')).toBeVisible();
    await expect(page.getByText('No modules yet')).toBeVisible();

    await sidebar.locator('.ws-switcher').click();
    await sidebar.locator('.ws-menu').getByRole('link', { name: 'New workspace' }).click();
    await expect(form(page)).toBeVisible();
    await form(page).getByLabel('Name').fill('Mathematics');
    await form(page).getByRole('button', { name: 'Create workspace' }).click();
    await expect(heading(page, 'Mathematics')).toBeVisible();

    await sidebar.locator('.ws-switcher').click();
    await expect(sidebar.locator('.ws-menu').getByRole('link')).toHaveText(['Biology', 'Mathematics', 'All workspaces', 'New workspace']);
    await page.keyboard.press('Escape');
    await expect(sidebar.locator('.ws-switcher')).toBeFocused();
    await sidebar.locator('.ws-switcher').click();
    await sidebar.locator('.ws-menu').getByRole('link', { name: 'Biology' }).click();
    await expect(heading(page, 'Biology')).toBeVisible();
});

test('editing, archiving and restoring a workspace', async ({ page }) => {
    await page.setViewportSize(desktop);
    await openStudentHome(page, makeStudentWithWorkspaces([['Biology', 'green', 'microscope'], ['Spanish', 'amber', 'languages']]));
    await page.getByRole('link', { name: 'Biology' }).last().click();
    await expect(heading(page, 'Biology')).toBeVisible();

    await page.getByRole('button', { name: 'Edit' }).click();
    await expect(form(page).getByLabel('Name')).toHaveValue('Biology');
    await form(page).getByLabel('Name').fill('Human biology');
    await form(page).getByRole('button', { name: 'Save changes' }).click();
    await expect(heading(page, 'Human biology')).toBeVisible();

    await page.getByRole('button', { name: 'Edit' }).click();
    await form(page).getByRole('button', { name: 'Archive' }).click();
    await expect(page.getByText('Human biology is archived.')).toBeVisible();
    await expect(page.locator('main').getByRole('link', { name: 'Human biology' })).toBeHidden();

    await page.getByText('Archived (1)').click();
    await markPage(page);
    await page.getByRole('button', { name: 'Restore Human biology' }).click();
    await expect(page.getByText('Human biology is back in your workspaces.')).toBeVisible();
    await expect(page.locator('main').getByRole('link', { name: 'Human biology' })).toBeVisible();
    expect(await wasReloaded(page)).toBe(false);
});

test('phone: the sections sit in a bottom tab bar, and the menu holds the switcher', async ({ page }) => {
    await page.setViewportSize(phone);
    await openStudentHome(page, makeStudentWithWorkspaces([['Biology', 'green', 'microscope']]));
    await page.locator('main').getByRole('link', { name: 'Biology' }).click();

    const tabs = page.locator('.app-tabbar');
    await expect(tabs).toBeVisible();
    await expect(tabs.getByRole('link', { name: 'Overview' })).toHaveAttribute('aria-current', 'page');
    await tabs.getByRole('link', { name: 'Progress' }).click();
    await expect(heading(page, 'Progress')).toBeVisible();

    await page.getByRole('button', { name: 'Open menu' }).click();
    await expect(page.locator('#app-drawer .ws-switcher')).toBeVisible();
});

for (const [name, viewport] of Object.entries({ desktop, phone })) {
    test(`every colour in workspaces comes from a token: ${name}`, async ({ page }) => {
        await page.setViewportSize(viewport);
        await openStudentHome(page, makeStudentWithWorkspaces([['Biology', 'green', 'microscope'], ['Mathematics', 'blue', 'sigma'], ['Spanish', 'amber', 'languages'], ['Art', 'pink', 'palette']]));
        await useSentinelTheme(page);
        const states = { 'my workspaces': await foreignColours(page) };

        await openForm(page);
        await form(page).getByRole('button', { name: 'Create workspace' }).click();
        await form(page).getByText('Give the workspace a name.').waitFor();
        await form(page).getByText('Purple', { exact: true }).click({ force: true });
        await form(page).getByText('Brain', { exact: true }).click({ force: true });
        states['form, error, colour and icon picked'] = await foreignColours(page);
        await page.keyboard.press('Escape');

        await page.locator('main').getByRole('link', { name: 'Biology' }).click();
        await heading(page, 'Biology').waitFor();
        await useSentinelTheme(page);
        states['workspace overview'] = await foreignColours(page);
        if (name === 'desktop') {
            await page.locator('.app-sidebar .ws-switcher').click();
            states['switcher open'] = await foreignColours(page);
            await page.keyboard.press('Escape');
        }

        for (const [state, colours] of Object.entries(states)) {
            expect(colours, `${state}: colours not from a token`).toEqual([]);
        }
    });
}

for (const theme of THEMES) {
    test(`axe finds no violations in workspaces: ${theme}`, async ({ page }) => {
        await page.setViewportSize(desktop);
        await openStudentHome(page, makeStudentWithWorkspaces([['Biology', 'green', 'microscope'], ['Mathematics', 'blue', 'sigma']]));
        await useTheme(page, theme);
        expect(await analyse(page)).toEqual([]);

        await openForm(page);
        expect(await analyse(page)).toEqual([]);
        await page.keyboard.press('Escape');

        await page.locator('main').getByRole('link', { name: 'Biology' }).click();
        await heading(page, 'Biology').waitFor();
        await useTheme(page, theme);
        expect(await analyse(page)).toEqual([]);
        await page.locator('.app-sidebar .ws-switcher').click();
        expect(await analyse(page)).toEqual([]);
    });
}

test('workspaces never scroll sideways at 320 px, even with 200% text', async ({ page }) => {
    await openStudentHome(page, makeStudentWithWorkspaces([['A workspace with a rather long name indeed', 'teal', 'globe']]));
    await page.setViewportSize({ width: 320, height: 800 });
    const check = async (where) => {
        for (const zoom of ['100%', '200%']) {
            await page.evaluate((size) => (document.documentElement.style.fontSize = size), zoom);
            const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
            expect(overflow, `${where}, 320 px at ${zoom} text`).toBeLessThanOrEqual(0);
        }
        await page.evaluate(() => (document.documentElement.style.fontSize = ''));
    };

    await check('my workspaces');
    await page.locator('main').getByRole('link', { name: /rather long/ }).click();
    await page.getByRole('heading', { level: 1 }).waitFor();
    await check('workspace overview');
    await page.getByRole('button', { name: 'Edit' }).click();
    await check('edit dialog');
});
