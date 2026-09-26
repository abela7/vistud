import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { foreignColours, openStudentHome, THEMES, useSentinelTheme, useTheme } from './support.js';

/*
| The signed-in frame (DESIGN.md §6.1, §7.2): a sidebar on desktop that
| collapses to icons, and a slide-in menu on tablets and phones.
*/

const desktop = { width: 1440, height: 900 };
const tablet = { width: 834, height: 1194 };
const phone = { width: 390, height: 844 };
const drawer = (page) => page.locator('#app-drawer');
const menuButton = (page) => page.getByRole('button', { name: 'Open menu' });

test.use({ reducedMotion: 'reduce' });

test('desktop: the sidebar is always there, collapses to icons, and remembers it', async ({ page }) => {
    await page.setViewportSize(desktop);
    await openStudentHome(page);
    const sidebar = page.locator('.app-sidebar');

    await expect(sidebar).toBeVisible();
    await expect(menuButton(page)).toBeHidden();
    await expect(sidebar.getByRole('link', { name: 'All workspaces' })).toHaveAttribute('aria-current', 'page');

    await sidebar.getByRole('button', { name: 'Collapse sidebar' }).click();
    await expect(page.locator('html')).toHaveAttribute('data-sidebar', 'collapsed');
    expect((await sidebar.boundingBox()).width).toBeLessThan(90);
    await expect(sidebar.getByRole('link', { name: 'All workspaces' })).toBeVisible();

    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-sidebar', 'collapsed');
    await sidebar.getByRole('button', { name: 'Expand sidebar' }).click();
    expect((await sidebar.boundingBox()).width).toBeGreaterThan(200);
});

for (const [name, viewport] of Object.entries({ tablet, phone })) {
    test(`${name}: the menu slides in, closes with Esc or a tap outside, and gives focus back`, async ({ page }) => {
        await page.setViewportSize(viewport);
        await openStudentHome(page);

        await expect(page.locator('.app-sidebar')).toBeHidden();
        await expect(drawer(page)).toBeHidden();

        await menuButton(page).click();
        await expect(drawer(page)).toBeVisible();
        await expect(menuButton(page)).toHaveAttribute('aria-expanded', 'true');
        expect(await page.evaluate(() => document.getElementById('app-drawer').contains(document.activeElement))).toBe(true);

        await page.keyboard.press('Escape');
        await expect(drawer(page)).toBeHidden();
        await expect(menuButton(page)).toBeFocused();
        await expect(menuButton(page)).toHaveAttribute('aria-expanded', 'false');

        await menuButton(page).click();
        await page.mouse.click(viewport.width - 10, viewport.height / 2); // the dimmed backdrop
        await expect(drawer(page)).toBeHidden();

        await menuButton(page).click();
        await drawer(page).getByRole('link', { name: 'Journal' }).click();
        await page.waitForURL('**/journal');
    });
}

test('the account menu opens, closes with Esc or a click outside, and offers log out', async ({ page }) => {
    await page.setViewportSize(desktop);
    await openStudentHome(page);
    const account = page.locator('[data-menu-button]');
    const panel = page.locator('#account-menu');

    await account.click();
    await expect(panel).toBeVisible();
    await expect(panel.getByRole('button', { name: 'Log out' })).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(panel).toBeHidden();
    await expect(account).toBeFocused();

    await account.click();
    await page.mouse.click(700, 600);
    await expect(panel).toBeHidden();

    await account.click();
    await panel.getByRole('button', { name: 'Log out' }).click();
    await page.waitForURL('**/login');
});

for (const [name, viewport] of Object.entries({ desktop, phone })) {
    test(`every colour in the frame comes from a token: ${name}`, async ({ page }) => {
        await page.setViewportSize(viewport);
        await openStudentHome(page);
        await useSentinelTheme(page);
        const states = { idle: await foreignColours(page) };

        await page.locator('[data-menu-button]').click();
        await page.locator('#account-menu .menu-item').first().hover();
        states['account menu'] = await foreignColours(page);
        await page.keyboard.press('Escape');

        if (name === 'desktop') {
            await page.locator('.app-sidebar .nav-item').nth(1).hover();
            states['nav hover'] = await foreignColours(page);
            await page.getByRole('button', { name: 'Collapse sidebar' }).click();
            states.collapsed = await foreignColours(page);
        } else {
            await menuButton(page).click();
            await expect(drawer(page)).toBeVisible();
            states['menu open'] = await foreignColours(page);
        }

        for (const [state, colours] of Object.entries(states)) {
            expect(colours, `${state}: colours not from a token`).toEqual([]);
        }
    });
}

for (const theme of THEMES) {
    test(`axe finds no violations in the frame: ${theme}`, async ({ page }) => {
        const analyse = async () => (await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze()).violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target).join(' ')}`);

        await page.setViewportSize(desktop);
        await openStudentHome(page);
        await useTheme(page, theme);
        expect(await analyse()).toEqual([]);
        await page.locator('[data-menu-button]').click();
        expect(await analyse()).toEqual([]);
        await page.keyboard.press('Escape');

        await page.setViewportSize(phone);
        await menuButton(page).click();
        await expect(drawer(page)).toBeVisible();
        expect(await analyse()).toEqual([]);
    });
}

test('the frame never scrolls sideways at 320 px, even with 200% text', async ({ page }) => {
    await openStudentHome(page);
    for (const zoom of ['100%', '200%']) {
        await page.setViewportSize({ width: 320, height: 800 });
        await page.evaluate((size) => (document.documentElement.style.fontSize = size), zoom);
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        expect(overflow, `320 px at ${zoom} text`).toBeLessThanOrEqual(0);
    }
});
