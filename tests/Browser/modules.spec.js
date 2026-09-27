import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { foreignColours, makeStudentWithModules, makeStudentWithWorkspaces, openStudentHome, THEMES, useSentinelTheme, useTheme } from './support.js';

/* A workspace's modules as cards, and each module's and folder's own page (docs/specs/workspaces.md). */

const desktop = { width: 1440, height: 900 };
const phone = { width: 390, height: 844 };
const dialog = (page) => page.locator('#structure-dialog');
const titles = (page) => page.locator('.module-tile h2');
// With a menu open, only the menu is checked: it covers whatever sits under it, which can't be used until it closes.
const analyse = async (page, only = null) => {
    const builder = new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']);
    return (await (only ? builder.include(only) : builder).analyze()).violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target).join(' ')}`);
};

test.use({ reducedMotion: 'reduce' });
test.describe.configure({ timeout: 60_000 });

async function openModules(page, email) {
    await openStudentHome(page, email);
    await page.locator('main').getByRole('link', { name: 'Biology' }).click();
    await page.getByRole('heading', { level: 1, name: 'Biology' }).waitFor();
    // The tab bar on a phone, the sidebar on a wide screen.
    const nav = page.viewportSize().width < 768 ? '.app-tabbar' : '.app-sidebar';
    await page.locator(nav).getByRole('link', { name: 'Modules' }).click();
    await page.getByRole('heading', { level: 1, name: 'Modules' }).waitFor();
    await page.waitForLoadState('load');
}

async function openPlace(page, name) {
    await page.locator('main').getByRole('link', { name, exact: true }).click();
    await page.getByRole('heading', { level: 1, name }).waitFor();
    await page.waitForLoadState('load');
}

async function menu(page, name) {
    await page.getByRole('button', { name: `Actions for ${name}`, exact: true }).click();
    return page.locator('.row-menu:not([hidden])');
}

async function newInPlace(page, what) {
    await page.locator('main').getByRole('button', { name: 'New', exact: true }).click();
    await page.locator('#new-menu').getByRole('button', { name: what }).click();
}

test('a student adds modules as cards, reorders them, and opens one to build folders inside', async ({ page }) => {
    await page.setViewportSize(desktop);
    await openModules(page, makeStudentWithWorkspaces([['Biology', 'green', 'microscope']]));
    await expect(titles(page)).toHaveCount(0);

    await page.getByRole('button', { name: 'New module' }).click();
    await expect(dialog(page).getByLabel('Title')).toBeFocused();
    await dialog(page).getByRole('button', { name: 'Add module' }).click();
    await expect(dialog(page).getByText('Give the module a title.')).toBeVisible();
    await dialog(page).getByLabel('Title').fill('Week 1: Cells');
    await dialog(page).getByRole('button', { name: 'Add module' }).click();
    await expect(dialog(page)).toBeHidden();
    await expect(page.getByRole('status').filter({ hasText: 'Week 1: Cells is added.' })).toBeVisible();

    await page.getByRole('button', { name: 'New module' }).click();
    await dialog(page).getByLabel('Title').fill('Week 0: Introduction');
    await dialog(page).getByRole('button', { name: 'Add module' }).click();
    await expect(titles(page)).toHaveText(['Week 1: Cells', 'Week 0: Introduction']);

    // From its menu, the keyboard's way.
    const earlier = (await menu(page, 'Week 0: Introduction')).getByRole('button', { name: 'Move earlier' });
    await earlier.focus();
    await page.keyboard.press('Enter');
    await expect(titles(page)).toHaveText(['Week 0: Introduction', 'Week 1: Cells']);
    await page.reload();
    await expect(titles(page)).toHaveText(['Week 0: Introduction', 'Week 1: Cells']);

    // A module is a page: folders inside it are pages too.
    await openPlace(page, 'Week 1: Cells');
    await expect(page.getByText('Nothing here yet')).toBeVisible();
    await newInPlace(page, 'Folder');
    await expect(dialog(page).getByRole('heading', { name: 'New folder in Week 1: Cells' })).toBeVisible();
    await dialog(page).getByLabel('Name').fill('Labs');
    await dialog(page).getByRole('button', { name: 'Add folder' }).click();
    await expect(page.locator('.folder-tile')).toContainText('Labs');

    await openPlace(page, 'Labs');
    await expect(page.getByRole('navigation', { name: 'Path' })).toContainText('Week 1: Cells');
    await newInPlace(page, 'Folder');
    await dialog(page).getByLabel('Name').fill('Lab 1');
    await dialog(page).getByRole('button', { name: 'Add folder' }).click();
    await expect(page.locator('.folder-tile')).toContainText('Lab 1');

    await page.getByRole('navigation', { name: 'Path' }).getByRole('link', { name: 'Week 1: Cells' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Week 1: Cells' })).toBeVisible();
    await expect(page.locator('.folder-tile').filter({ hasText: 'Labs' })).toContainText('1 item');
});

test('moving, renaming and deleting folders, and the refusals', async ({ page }) => {
    await page.setViewportSize(desktop);
    await openModules(page, makeStudentWithModules());
    await openPlace(page, 'Week 1: Cells');

    await (await menu(page, 'Labs')).getByRole('button', { name: 'Move to…' }).click();
    await expect(dialog(page).getByRole('heading', { name: 'Move “Labs”' })).toBeFocused();
    await expect(dialog(page).getByLabel('Lab 1: microscopes')).toBeDisabled();
    await dialog(page).getByLabel('Week 2: Cell division').check();
    await dialog(page).getByRole('button', { name: 'Move', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Labs is moved.' })).toBeVisible();
    await expect(page.locator('.folder-tile').filter({ hasText: 'Labs' })).toHaveCount(0);

    await (await menu(page, 'Reading')).getByRole('button', { name: 'Rename' }).click();
    await dialog(page).getByLabel('Name').fill('Reading list');
    await dialog(page).getByRole('button', { name: 'Rename' }).click();
    await expect(page.getByRole('link', { name: 'Reading list', exact: true })).toBeVisible();
    await (await menu(page, 'Reading list')).getByRole('button', { name: 'Delete' }).click();
    await dialog(page).getByRole('button', { name: 'Delete', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Reading list is deleted.' })).toBeVisible();

    await page.getByRole('navigation', { name: 'Path' }).getByRole('link', { name: 'Modules' }).click();
    await openPlace(page, 'Week 2: Cell division');
    await (await menu(page, 'Labs')).getByRole('button', { name: 'Delete' }).click();
    await dialog(page).getByRole('button', { name: 'Delete', exact: true }).click();
    await expect(dialog(page).getByText('Move or delete what\'s inside first.')).toBeVisible();
    await page.keyboard.press('Escape');

    // Deleting the folder you're in takes you up a level.
    await openPlace(page, 'Labs');
    await openPlace(page, 'Lab 1: microscopes');
    await (await menu(page, 'Lab 1: microscopes')).getByRole('button', { name: 'Delete' }).click();
    await dialog(page).getByRole('button', { name: 'Delete', exact: true }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Labs' })).toBeVisible();
    await expect(page.getByText('Nothing here yet')).toBeVisible();
});

test('phone: modules and their dialog work from the tab bar', async ({ page }) => {
    await page.setViewportSize(phone);
    await openModules(page, makeStudentWithModules());
    await expect(page.locator('.app-tabbar').getByRole('link', { name: 'Modules' })).toHaveAttribute('aria-current', 'page');
    await page.getByRole('button', { name: 'New module' }).click();
    await expect(dialog(page).getByLabel('Title')).toBeVisible();
});

for (const [name, viewport] of Object.entries({ desktop, phone })) {
    test(`every colour in modules comes from a token: ${name}`, async ({ page }) => {
        await page.setViewportSize(viewport);
        await openModules(page, makeStudentWithModules());
        await useSentinelTheme(page);
        const states = { modules: await foreignColours(page) };

        await page.getByRole('button', { name: 'New module' }).click();
        await dialog(page).getByRole('button', { name: 'Add module' }).click();
        await dialog(page).getByText('Give the module a title.').waitFor();
        states['module dialog, error'] = await foreignColours(page);
        await page.keyboard.press('Escape');

        await openPlace(page, 'Week 1: Cells');
        await useSentinelTheme(page);
        states['module page'] = await foreignColours(page);
        await menu(page, 'Labs');
        states['menu open'] = await foreignColours(page);
        await page.keyboard.press('Escape');
        await (await menu(page, 'Labs')).getByRole('button', { name: 'Move to…' }).click();
        await dialog(page).getByLabel('Week 2: Cell division').check();
        states['move dialog'] = await foreignColours(page);
        await page.keyboard.press('Escape');

        await openPlace(page, 'Labs');
        await useSentinelTheme(page);
        states['folder page'] = await foreignColours(page);

        for (const [state, colours] of Object.entries(states)) {
            expect(colours, `${state}: colours not from a token`).toEqual([]);
        }
    });
}

for (const theme of THEMES) {
    test(`axe finds no violations in modules: ${theme}`, async ({ page }) => {
        await page.setViewportSize(desktop);
        await openModules(page, makeStudentWithModules());
        await useTheme(page, theme);
        expect(await analyse(page), 'modules').toEqual([]);

        await openPlace(page, 'Week 1: Cells');
        await useTheme(page, theme);
        expect(await analyse(page), 'module page').toEqual([]);
        await menu(page, 'Labs');
        expect(await analyse(page, '.row-menu:not([hidden])'), 'menu').toEqual([]);
        await page.keyboard.press('Escape');
        await (await menu(page, 'Labs')).getByRole('button', { name: 'Move to…' }).click();
        await dialog(page).getByRole('heading', { name: 'Move “Labs”' }).waitFor();
        expect(await analyse(page), 'move dialog').toEqual([]);
        await page.keyboard.press('Escape');

        await openPlace(page, 'Labs');
        await useTheme(page, theme);
        expect(await analyse(page), 'folder page').toEqual([]);
    });
}

test('modules never scroll sideways at 320 px, even with 200% text', async ({ page }) => {
    await openModules(page, makeStudentWithModules());
    await page.setViewportSize({ width: 320, height: 800 });
    const check = async (where) => {
        for (const zoom of ['100%', '200%']) {
            await page.evaluate((size) => (document.documentElement.style.fontSize = size), zoom);
            const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
            expect(overflow, `${where}, 320 px at ${zoom} text`).toBeLessThanOrEqual(0);
        }
        await page.evaluate(() => (document.documentElement.style.fontSize = ''));
    };
    await check('modules');
    await openPlace(page, 'Week 1: Cells');
    await check('module page');
});
