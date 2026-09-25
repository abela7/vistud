import { test, expect } from '@playwright/test';
import { foreignColours, useSentinelTheme } from './support.js';

/*
| Layer 3 of theme enforcement (ADR 0003 §6.3, DESIGN.md §3.5): with the
| sentinel theme applied, every colour on the page, in every state, must be
| a sentinel value, gradients and shadows included.
*/

const viewports = { desktop: { width: 1440, height: 900 }, mobile: { width: 390, height: 844 } };

for (const [name, viewport] of Object.entries(viewports)) {
    test.describe(`login, ${name}`, () => {
        test.use({ viewport, reducedMotion: 'reduce' });

        test('every colour comes from a token, in every state', async ({ page }) => {
            const states = {};

            await page.goto('/login');
            await useSentinelTheme(page);
            states.idle = await foreignColours(page);

            await page.getByRole('button', { name: 'Log in' }).hover();
            states['primary hover'] = await foreignColours(page);

            await page.mouse.move(0, 0);
            await page.getByRole('button', { name: 'Log in' }).dispatchEvent('mousedown');
            states['primary pressed'] = await foreignColours(page);

            await page.keyboard.press('Tab');
            await page.getByLabel('Email').focus();
            states['field focus'] = await foreignColours(page);

            await page.getByLabel('Keep me logged in on this device').check();
            await page.getByLabel('Keep me logged in on this device').focus();
            states['checkbox checked and focused'] = await foreignColours(page);

            await page.getByRole('button', { name: 'Show password' }).click();
            states['password shown'] = await foreignColours(page);

            await page.getByText('Dark', { exact: true }).hover();
            states['segmented hover'] = await foreignColours(page);

            await page.evaluate(() => {
                const button = document.querySelector('button[type="submit"]');
                button.setAttribute('aria-busy', 'true');
            });
            states['primary loading'] = await foreignColours(page);

            await page.evaluate(() => {
                const button = document.querySelector('button[type="submit"]');
                button.removeAttribute('aria-busy');
                button.disabled = true;
            });
            states['primary disabled'] = await foreignColours(page);

            // Server-side states: missing fields, then a refused login.
            await page.goto('/login');
            await page.getByRole('button', { name: 'Log in' }).click();
            await page.waitForURL('**/login');
            await useSentinelTheme(page);
            states['field errors'] = await foreignColours(page);

            await page.getByLabel('Email').fill('nobody@example.test');
            await page.getByLabel('Password', { exact: true }).fill('not-the-password');
            await page.getByRole('button', { name: 'Log in' }).click();
            await page.waitForURL('**/login');
            await useSentinelTheme(page);
            states['refused login'] = await foreignColours(page);

            for (const [state, colours] of Object.entries(states)) {
                expect(colours, `${state}: colours not from a token`).toEqual([]);
            }
        });
    });
}
