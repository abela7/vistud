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

/** A new course opens its setup sheet (course-setup.spec.js); these tests are about something else, so they close it. */
async function dismissSetup(page) {
    await expect(page.locator('#course-setup')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.locator('#course-setup')).toBeHidden();
}

/** The New course page (a page of its own since the course guide), reached from Home or the switcher. */
async function openNewCourse(page) {
    await page.getByRole('link', { name: 'New course' }).first().click();
    await expect(heading(page, 'New course')).toBeVisible();
}

test('a student creates their first workspace, opens it, and creates a second from the switcher', async ({ page }) => {
    await page.setViewportSize(desktop);
    await openStudentHome(page);
    await expect(page.getByText('Create your first course')).toBeVisible();

    await openNewCourse(page);
    await expect(page.getByLabel('Name', { exact: true })).toBeFocused();
    await page.getByRole('button', { name: 'Create course' }).click();
    await expect(page.getByText('Give the course a name.')).toBeVisible();

    await page.getByLabel('Name', { exact: true }).fill('Biology');
    await page.getByText('Green', { exact: true }).click({ force: true });
    await page.getByText('Microscope', { exact: true }).click({ force: true });
    await page.locator('.course-new-more summary').click();
    await page.getByLabel('Course code').fill('BIO101');
    await page.getByRole('radio', { name: /I'll do it myself/ }).check({ force: true });
    await page.getByRole('button', { name: 'Create course' }).click();

    await expect(heading(page, 'Biology')).toBeVisible();
    await dismissSetup(page);
    await page.getByRole('button', { name: 'More for Biology' }).click();
    await page.getByRole('button', { name: 'Edit course' }).click();
    await expect(form(page).getByLabel('Course code (optional)')).toHaveValue('BIO101');
    await page.keyboard.press('Escape');
    const sidebar = page.locator('.app-sidebar');
    await expect(sidebar.getByRole('link', { name: 'Home', exact: true })).toHaveAttribute('aria-current', 'page');

    await sidebar.getByRole('link', { name: 'Modules' }).click();
    await expect(heading(page, 'Modules')).toBeVisible();
    await expect(page.getByRole('button', { name: 'New module' })).toBeVisible();

    await sidebar.locator('.ws-switcher').click();
    await sidebar.locator('.ws-menu').getByRole('link', { name: 'New course' }).click();
    await expect(heading(page, 'New course')).toBeVisible();
    await page.getByLabel('Name', { exact: true }).fill('Mathematics');
    await page.getByRole('radio', { name: /I'll do it myself/ }).check({ force: true });
    await page.getByRole('button', { name: 'Create course' }).click();
    await expect(heading(page, 'Mathematics')).toBeVisible();
    await dismissSetup(page);

    await sidebar.locator('.ws-switcher').click();
    await expect(sidebar.locator('.ws-menu').getByRole('link')).toHaveText(['Biology', 'Mathematics', 'All courses', 'New course']);
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

    await page.getByRole('button', { name: 'More for Biology' }).click();
    await page.getByRole('button', { name: 'Edit course' }).click();
    await expect(form(page).getByLabel('Name')).toHaveValue('Biology');
    await form(page).getByLabel('Name').fill('Human biology');
    await form(page).getByRole('button', { name: 'Save changes' }).click();
    await expect(heading(page, 'Human biology')).toBeVisible();

    await page.getByRole('button', { name: 'More for Human biology' }).click();
    await page.getByRole('button', { name: 'Edit course' }).click();
    await form(page).getByRole('button', { name: 'Archive' }).click();
    await expect(page.getByText('Human biology is archived.')).toBeVisible();
    await expect(page.locator('main').getByRole('link', { name: 'Human biology' })).toBeHidden();

    await page.getByText('Archived (1)').click();
    await markPage(page);
    await page.getByRole('button', { name: 'Restore Human biology' }).click();
    await expect(page.getByText('Human biology is back in your courses.')).toBeVisible();
    await expect(page.locator('main').getByRole('link', { name: 'Human biology' })).toBeVisible();
    expect(await wasReloaded(page)).toBe(false);
});

test('deleting a workspace from the card menu with confirmation modal', async ({ page }) => {
    await page.setViewportSize(desktop);
    await openStudentHome(page, makeStudentWithWorkspaces([['Biology', 'green', 'microscope'], ['Spanish', 'amber', 'languages']]));

    await page.getByRole('button', { name: 'Actions for Biology' }).click();
    await page.getByRole('button', { name: 'Delete course' }).click();

    const dialog = page.locator('#workspace-delete-dialog');
    await expect(dialog).toBeVisible();
    await expect(dialog.getByText('Are you sure you want to delete Biology?')).toBeVisible();

    await dialog.getByRole('button', { name: 'Delete course' }).click();
    await expect(page.getByText('Biology was deleted.')).toBeVisible();
    await expect(page.locator('main').getByRole('link', { name: 'Biology' })).toBeHidden();
    await expect(page.locator('main').getByRole('link', { name: 'Spanish' })).toBeVisible();
});

test('phone: the sections sit in a bottom tab bar, and the menu holds the switcher', async ({ page }) => {
    await page.setViewportSize(phone);
    await openStudentHome(page, makeStudentWithWorkspaces([['Biology', 'green', 'microscope']]));
    await page.locator('main').getByRole('link', { name: 'Biology' }).click();

    const tabs = page.locator('.app-tabbar');
    await expect(tabs).toBeVisible();
    await expect(tabs.getByRole('link', { name: 'Home', exact: true })).toHaveAttribute('aria-current', 'page');
    // Home, Modules and Cards are tabs; the rest (Progress here) is in More, with Ask beside it.
    await expect(tabs.getByRole('button', { name: 'Ask', exact: true })).toBeVisible();
    await tabs.getByRole('button', { name: 'More', exact: true }).click();
    await page.locator('#more-sheet').getByRole('link', { name: 'Progress' }).click();
    await expect(heading(page, 'Progress')).toBeVisible();
    await expect(tabs.getByRole('button', { name: 'More', exact: true })).toHaveAttribute('data-current', '');

    await page.getByRole('button', { name: 'Open menu' }).click();
    await expect(page.locator('#app-drawer .ws-switcher')).toBeVisible();
});

for (const [name, viewport] of Object.entries({ desktop, phone })) {
    test(`every colour in workspaces comes from a token: ${name}`, async ({ page }) => {
        await page.setViewportSize(viewport);
        await openStudentHome(page, makeStudentWithWorkspaces([['Biology', 'green', 'microscope'], ['Mathematics', 'blue', 'sigma'], ['Spanish', 'amber', 'languages'], ['Art', 'pink', 'palette']]));
        await useSentinelTheme(page);
        const states = { 'my workspaces': await foreignColours(page) };

        await openNewCourse(page);
        await useSentinelTheme(page);
        await page.getByRole('button', { name: 'Create course' }).click();
        await page.getByText('Give the course a name.').waitFor();
        await page.getByText('Purple', { exact: true }).click({ force: true });
        await page.getByText('Brain', { exact: true }).click({ force: true });
        states['new course page, error, colour and icon picked'] = await foreignColours(page);
        await page.getByRole('link', { name: 'Cancel' }).click();
        await page.locator('main').getByRole('link', { name: 'Biology' }).first().waitFor();

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

        await openNewCourse(page);
        await useTheme(page, theme);
        expect(await analyse(page)).toEqual([]);
        await page.getByRole('link', { name: 'Cancel' }).click();
        await page.locator('main').getByRole('link', { name: 'Biology' }).first().waitFor();

        await page.locator('main').getByRole('link', { name: 'Biology' }).click();
        await heading(page, 'Biology').waitFor();
        await useTheme(page, theme);
        expect(await analyse(page)).toEqual([]);
        await page.locator('.app-sidebar .ws-switcher').click();
        expect(await analyse(page)).toEqual([]);
    });
}

test('workspaces never scroll sideways at 320 px, even with 200% text', async ({ page }) => {
    await openStudentHome(page, makeStudentWithWorkspaces([['A course with a rather long name indeed', 'teal', 'globe']]));
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
    await page.getByRole('heading', { level: 1, name: /rather long/ }).waitFor();
    await check('workspace overview');
    await page.getByRole('button', { name: /^More for/ }).click();
    await page.getByRole('button', { name: 'Edit course' }).click();
    await check('edit dialog');
});
