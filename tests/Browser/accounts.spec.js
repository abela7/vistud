import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { foreignColours, makeNamedStudent, markPage, openAccounts, THEMES, useSentinelTheme, useTheme, wasReloaded } from './support.js';

/*
| The admin Accounts page (ADR 0003 §10.1–10.3): a searchable list, and a
| dialog per account whose actions are confirmed before they run. It works
| without reloading the page.
*/

const desktop = { width: 1440, height: 900 };
const phone = { width: 390, height: 844 };
const dialog = (page) => page.locator('#account-dialog');

test.use({ reducedMotion: 'reduce' });
// Each test signs an admin in with a recovery code and a password check first; slower machines need more than the default 30 s.
test.describe.configure({ timeout: 60_000 });

for (const [name, viewport] of Object.entries({ desktop, phone })) {
    test(`${name}: suspend and reactivate an account, confirmed in the dialog, without a reload`, async ({ page }) => {
        await page.setViewportSize(viewport);
        const email = makeNamedStudent('Mary Somerville');
        await openAccounts(page, email);
        await markPage(page);
        const manage = page.getByRole('button', { name: 'Manage Mary Somerville' });

        await manage.click();
        await expect(dialog(page)).toBeVisible();
        await expect(dialog(page).getByRole('heading', { name: 'Mary Somerville' })).toBeFocused();
        await page.keyboard.press('Escape');
        await expect(dialog(page)).toBeHidden();
        await expect(manage).toBeFocused();

        await manage.click();
        await dialog(page).getByRole('button', { name: 'Suspend account' }).click();
        await expect(dialog(page).getByRole('heading', { name: 'Suspend Mary Somerville?' })).toBeFocused();
        await dialog(page).getByRole('button', { name: 'Back' }).click();
        await expect(dialog(page).getByRole('heading', { name: 'Mary Somerville' })).toBeFocused();
        await dialog(page).getByRole('button', { name: 'Suspend account' }).click();
        await expect(dialog(page).getByRole('heading', { name: 'Suspend Mary Somerville?' })).toBeFocused();
        await dialog(page).getByRole('button', { name: 'Suspend account' }).click();

        await expect(dialog(page)).toBeHidden();
        await expect(page.getByRole('status').filter({ hasText: 'Mary Somerville is suspended' })).toBeVisible();
        await expect(page.getByRole('listitem').filter({ hasText: email }).getByText('Suspended', { exact: true })).toBeVisible();
        await expect(manage).toBeFocused();

        await manage.click();
        await dialog(page).getByRole('button', { name: 'Reactivate account' }).click();
        await dialog(page).getByRole('heading', { name: 'Reactivate Mary Somerville?' }).waitFor();
        await dialog(page).getByRole('button', { name: 'Reactivate account' }).click();
        await expect(page.getByRole('status').filter({ hasText: 'Mary Somerville can log in again.' })).toBeVisible();
        await expect(page.getByRole('listitem').filter({ hasText: email }).getByText('Suspended', { exact: true })).toBeHidden();

        expect(await wasReloaded(page)).toBe(false);
    });
}

test('your own account offers no actions', async ({ page }) => {
    await page.setViewportSize(desktop);
    const email = await openAccounts(page);
    await page.getByLabel('Search accounts').fill(email);
    await page.getByText('1 match', { exact: true }).waitFor();
    const mine = page.getByRole('listitem').filter({ has: page.getByText('You', { exact: true }) });

    await mine.getByRole('button', { name: /^Manage/ }).click();
    await expect(dialog(page).getByText('This is your account.')).toBeVisible();
    await expect(dialog(page).locator('.menu-item')).toHaveCount(0);
});

for (const [name, viewport] of Object.entries({ desktop, phone })) {
    test(`every colour on the Accounts page comes from a token: ${name}`, async ({ page }) => {
        await page.setViewportSize(viewport);
        const email = makeNamedStudent('Mary Somerville');
        await openAccounts(page, email);
        await useSentinelTheme(page);
        const states = { idle: await foreignColours(page) };

        await page.getByLabel('Search accounts').focus();
        states['search focus'] = await foreignColours(page);
        await page.getByRole('button', { name: 'Manage Mary Somerville' }).hover();
        states['manage hover'] = await foreignColours(page);

        await page.getByRole('button', { name: 'Manage Mary Somerville' }).click();
        await expect(dialog(page)).toBeVisible();
        await dialog(page).locator('.menu-item').first().hover();
        states['dialog, action hover'] = await foreignColours(page);

        await dialog(page).getByRole('button', { name: 'Make admin' }).click();
        await dialog(page).getByRole('heading', { name: 'Make Mary Somerville an admin?' }).waitFor();
        await dialog(page).getByRole('button', { name: 'Make admin' }).hover();
        states['confirm step, primary hover'] = await foreignColours(page);

        await page.keyboard.press('Escape');
        await page.getByLabel('Search accounts').fill('nobody-matches-this');
        await page.getByText('No accounts match').waitFor();
        states['no matches'] = await foreignColours(page);

        for (const [state, colours] of Object.entries(states)) {
            expect(colours, `${state}: colours not from a token`).toEqual([]);
        }
    });
}

for (const theme of THEMES) {
    test(`axe finds no violations on the Accounts page: ${theme}`, async ({ page }) => {
        const analyse = async () => (await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze()).violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target).join(' ')}`);

        await page.setViewportSize(desktop);
        const email = makeNamedStudent('Mary Somerville');
        await openAccounts(page);
        await useTheme(page, theme);
        expect(await analyse()).toEqual([]);

        await page.getByLabel('Search accounts').fill(email);
        await page.getByText('1 match', { exact: true }).waitFor();
        await page.getByRole('button', { name: 'Manage Mary Somerville' }).click();
        await expect(dialog(page)).toBeVisible();
        expect(await analyse()).toEqual([]);

        await dialog(page).getByRole('button', { name: 'Suspend account' }).click();
        await dialog(page).getByRole('heading', { name: 'Suspend Mary Somerville?' }).waitFor();
        expect(await analyse()).toEqual([]);
    });
}

test('the Accounts page never scrolls sideways at 320 px, even with 200% text', async ({ page }) => {
    const email = makeNamedStudent('Mary Somerville');
    await openAccounts(page, email);
    await page.setViewportSize({ width: 320, height: 800 });
    for (const zoom of ['100%', '200%']) {
        await page.evaluate((size) => (document.documentElement.style.fontSize = size), zoom);
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        expect(overflow, `320 px at ${zoom} text`).toBeLessThanOrEqual(0);
    }

    await page.getByRole('button', { name: 'Manage Mary Somerville' }).click();
    await expect(dialog(page)).toBeVisible();
    const dialogOverflow = await dialog(page).evaluate((el) => el.scrollWidth - el.clientWidth);
    expect(dialogOverflow, 'the dialog at 200% text').toBeLessThanOrEqual(0);
});
