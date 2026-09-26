import { test, expect } from '@playwright/test';
import { markPage, useTheme, wasReloaded } from './support.js';

/*
| Switching theme changes every colour and gradient treatment in place:
| no reload, no change to component markup (DESIGN.md §3.6).
*/

const treatments = (page) => page.evaluate(() => {
    const style = (selector) => getComputedStyle(document.querySelector(selector));
    return {
        brand: style('.surface-brand').backgroundImage,
        primary: style('.btn-primary').backgroundImage,
        surface: style('main').backgroundImage,
        text: style('body').color,
        canvas: style('body').backgroundColor,
    };
});

test('the appearance switcher changes gradients without a reload or markup change', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'light' });
    await page.goto('/login');
    await markPage(page);
    const markup = await page.evaluate(() => document.body.innerHTML);
    const light = await treatments(page);
    expect(await page.evaluate(() => document.documentElement.dataset.theme)).toBe('vistud-light');

    const changed = page.evaluate(() => new Promise((resolve) => {
        window.addEventListener('vistud:theme-changed', (event) => resolve(event.detail), { once: true });
    }));
    await page.getByText('Dark', { exact: true }).click();
    expect(await changed).toEqual({ mode: 'dark', theme: 'vistud-dark' });
    const dark = await treatments(page);

    for (const key of Object.keys(light)) {
        expect(dark[key], `${key} changes with the theme`).not.toBe(light[key]);
    }
    expect(dark.brand).toContain('linear-gradient');

    await useTheme(page, 'ember');
    const ember = await treatments(page);
    expect(ember.brand).not.toBe(dark.brand);
    expect(ember.brand).toContain('160deg');
    // Ember replaces the primary gradient with a solid fill; the button is unchanged.
    expect(ember.primary).toMatch(/^linear-gradient\((rgb\([^)]*\)), \1\)$/);

    expect(await wasReloaded(page)).toBe(false);
    expect(await page.evaluate(() => document.body.innerHTML)).toBe(markup);
});

test('the choice is remembered and applied before the first paint', async ({ page }) => {
    await page.goto('/login');
    await page.getByText('Dark', { exact: true }).click();

    // Without the app bundle, only the inline head script can set the theme.
    await page.route('**/build/assets/*.js', (route) => route.abort());
    await page.reload();
    expect(await page.evaluate(() => document.documentElement.dataset.theme)).toBe('vistud-dark');
    expect(await page.evaluate(() => document.documentElement.dataset.appearance)).toBe('dark');
});

test('system mode follows the device, live', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.goto('/login');
    expect(await page.evaluate(() => document.documentElement.dataset.theme)).toBe('vistud-dark');
    await markPage(page);

    await page.emulateMedia({ colorScheme: 'light' });
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'vistud-light');
    expect(await wasReloaded(page)).toBe(false);
});
