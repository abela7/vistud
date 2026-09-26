import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { foreignColours, makeStudentWithModules, makeStudentWithWorkspaces, openStudentHome, THEMES, useSentinelTheme, useTheme } from './support.js';

/* A workspace's modules and folders (docs/specs/workspaces.md, M2 step 2). */

const desktop = { width: 1440, height: 900 };
const phone = { width: 390, height: 844 };
const dialog = (page) => page.locator('#structure-dialog');
const module = (page, title) => page.locator('.module-card').filter({ has: page.getByText(title, { exact: true }) });
const titles = (page) => page.locator('.module-card .font-semibold.break-words');
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

async function menu(page, rowText) {
    const row = page.locator('.module-card > div, .folder-row').filter({ hasText: rowText }).first();
    await row.getByRole('button', { name: `Actions for ${rowText}` }).click();
    return row.locator('.row-menu');
}

test('a student adds modules, reorders them by dragging, and builds folders inside', async ({ page }) => {
    await page.setViewportSize(desktop);
    await openModules(page, makeStudentWithWorkspaces([['Biology', 'green', 'microscope']]));
    await expect(page.getByText('No modules yet')).toBeVisible();

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

    // Drag the second module above the first by its handle.
    const handle = module(page, 'Week 0: Introduction').locator('.drag-handle');
    const target = module(page, 'Week 1: Cells').locator('.drag-handle');
    const from = await handle.boundingBox();
    const to = await target.boundingBox();
    await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2);
    await page.mouse.down();
    await page.mouse.move(to.x + to.width / 2, to.y + 2, { steps: 12 });
    await page.mouse.up();
    await expect(titles(page)).toHaveText(['Week 0: Introduction', 'Week 1: Cells']);
    await page.reload();
    await expect(titles(page)).toHaveText(['Week 0: Introduction', 'Week 1: Cells']);

    await (await menu(page, 'Week 1: Cells')).getByRole('button', { name: 'New folder' }).click();
    await expect(dialog(page).getByRole('heading', { name: 'New folder in Week 1: Cells' })).toBeVisible();
    await dialog(page).getByLabel('Name').fill('Labs');
    await dialog(page).getByRole('button', { name: 'Add folder' }).click();
    await (await menu(page, 'Labs')).getByRole('button', { name: 'New folder inside' }).click();
    await dialog(page).getByLabel('Name').fill('Lab 1');
    await dialog(page).getByRole('button', { name: 'Add folder' }).click();
    await expect(module(page, 'Week 1: Cells').locator('.folder-list .folder-list')).toContainText('Lab 1');

    // Closing a module is remembered on this device.
    await module(page, 'Week 1: Cells').getByRole('button', { name: /^Week 1: Cells/ }).click();
    await expect(page.getByText('Lab 1', { exact: true })).toBeHidden();
    await page.reload();
    await expect(page.getByText('Lab 1', { exact: true })).toBeHidden();
});

test('moving, renaming and deleting folders, and the refusals', async ({ page }) => {
    await page.setViewportSize(desktop);
    await openModules(page, makeStudentWithModules());

    await (await menu(page, 'Labs')).getByRole('button', { name: 'Move to…' }).click();
    await expect(dialog(page).getByRole('heading', { name: 'Move “Labs”' })).toBeFocused();
    await expect(dialog(page).getByLabel('Lab 1: microscopes')).toBeDisabled();
    await dialog(page).getByLabel('Week 2: Cell division').check();
    await dialog(page).getByRole('button', { name: 'Move', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Labs is moved.' })).toBeVisible();
    await expect(module(page, 'Week 2: Cell division')).toContainText('Lab 1: microscopes');

    await (await menu(page, 'Labs')).getByRole('button', { name: 'Delete' }).click();
    await dialog(page).getByRole('button', { name: 'Delete', exact: true }).click();
    await expect(dialog(page).getByText('Move or delete what\'s inside first.')).toBeVisible();
    await page.keyboard.press('Escape');

    await (await menu(page, 'Reading')).getByRole('button', { name: 'Rename' }).click();
    await dialog(page).getByLabel('Name').fill('Reading list');
    await dialog(page).getByRole('button', { name: 'Rename' }).click();
    await expect(page.getByText('Reading list', { exact: true })).toBeVisible();

    await (await menu(page, 'Reading list')).getByRole('button', { name: 'Delete' }).click();
    await dialog(page).getByRole('button', { name: 'Delete', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Reading list is deleted.' })).toBeVisible();

    // The keyboard route for reordering modules.
    const moveDown = (await menu(page, 'Week 1: Cells')).getByRole('button', { name: 'Move down' });
    await moveDown.focus();
    await page.keyboard.press('Enter');
    await expect(titles(page)).toHaveText(['Week 2: Cell division', 'Week 1: Cells']);
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

        await menu(page, 'Labs');
        states['row menu open'] = await foreignColours(page);
        await page.keyboard.press('Escape');

        await (await menu(page, 'Labs')).getByRole('button', { name: 'Move to…' }).click();
        await dialog(page).getByLabel('Week 2: Cell division').check();
        states['move dialog'] = await foreignColours(page);
        await page.keyboard.press('Escape');

        await page.getByRole('button', { name: 'New module' }).click();
        await dialog(page).getByRole('button', { name: 'Add module' }).click();
        await dialog(page).getByText('Give the module a title.').waitFor();
        states['module dialog, error'] = await foreignColours(page);

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
        expect(await analyse(page)).toEqual([]);

        await menu(page, 'Labs');
        expect(await analyse(page, '.row-menu:not([hidden])')).toEqual([]);
        await page.keyboard.press('Escape');

        await (await menu(page, 'Labs')).getByRole('button', { name: 'Move to…' }).click();
        await dialog(page).getByRole('heading', { name: 'Move “Labs”' }).waitFor();
        expect(await analyse(page)).toEqual([]);
    });
}

test('modules never scroll sideways at 320 px, even with 200% text', async ({ page }) => {
    await openModules(page, makeStudentWithModules());
    await page.setViewportSize({ width: 320, height: 800 });
    for (const zoom of ['100%', '200%']) {
        await page.evaluate((size) => (document.documentElement.style.fontSize = size), zoom);
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        expect(overflow, `320 px at ${zoom} text`).toBeLessThanOrEqual(0);
    }
});
