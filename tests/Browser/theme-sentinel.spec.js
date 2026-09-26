import { test, expect } from '@playwright/test';
import { foreignColours, loginToChallenge, openTwoFactorSetup, startTwoFactorSetup, totp, openConfirmPassword, useSentinelTheme } from './support.js';

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

for (const [name, viewport] of Object.entries(viewports)) {
    test.describe(`two-factor challenge, ${name}`, () => {
        test.use({ viewport, reducedMotion: 'reduce' });

        test('every colour comes from a token, in every state', async ({ page }) => {
            const states = {};

            await loginToChallenge(page);
            await useSentinelTheme(page);
            states.idle = await foreignColours(page);

            await page.getByRole('button', { name: 'Verify' }).hover();
            states['primary hover'] = await foreignColours(page);

            await page.mouse.move(0, 0);
            await page.getByRole('button', { name: 'Verify' }).dispatchEvent('mousedown');
            states['primary pressed'] = await foreignColours(page);

            await page.getByLabel('Authentication code').focus();
            states['field focus'] = await foreignColours(page);

            await page.getByRole('button', { name: 'Use a recovery code instead' }).click();
            states['recovery-code mode'] = await foreignColours(page);

            await page.getByRole('button', { name: 'Use an authentication code instead' }).click();
            await page.evaluate(() => {
                document.querySelector('button[type="submit"]').setAttribute('aria-busy', 'true');
            });
            states['primary loading'] = await foreignColours(page);

            await page.evaluate(() => {
                document.querySelector('button[type="submit"]').removeAttribute('aria-busy');
            });
            await page.getByLabel('Authentication code').fill('000000');
            await page.getByRole('button', { name: 'Verify' }).click();
            await page.waitForURL('**/two-factor-challenge');
            await useSentinelTheme(page);
            states['wrong-code error'] = await foreignColours(page);

            for (const [state, colours] of Object.entries(states)) {
                expect(colours, `${state}: colours not from a token`).toEqual([]);
            }
        });
    });
}

for (const [name, viewport] of Object.entries(viewports)) {
    test.describe(`confirm password, ${name}`, () => {
        test.use({ viewport, reducedMotion: 'reduce' });

        test('every colour comes from a token, in every state', async ({ page }) => {
            const states = {};

            await openConfirmPassword(page);
            await useSentinelTheme(page);
            states.idle = await foreignColours(page);

            await page.getByLabel('Password', { exact: true }).focus();
            states['field focus'] = await foreignColours(page);

            await page.evaluate(() => {
                document.querySelector('button[type="submit"]').setAttribute('aria-busy', 'true');
            });
            states['primary loading'] = await foreignColours(page);

            await page.evaluate(() => {
                document.querySelector('button[type="submit"]').removeAttribute('aria-busy');
            });
            await page.getByLabel('Password', { exact: true }).fill('not-the-password');
            await page.getByRole('button', { name: 'Confirm' }).click();
            await page.waitForURL('**/user/confirm-password');
            await useSentinelTheme(page);
            states['wrong-password error'] = await foreignColours(page);

            for (const [state, colours] of Object.entries(states)) {
                expect(colours, `${state}: colours not from a token`).toEqual([]);
            }
        });
    });
}

for (const [name, viewport] of Object.entries(viewports)) {
    test.describe(`two-factor setup, ${name}`, () => {
        test.use({ viewport, reducedMotion: 'reduce' });

        test('every colour comes from a token, in every state', async ({ page }) => {
            const states = {};

            await openTwoFactorSetup(page);
            await useSentinelTheme(page);
            states.off = await foreignColours(page);

            const key = await startTwoFactorSetup(page);
            await useSentinelTheme(page);
            states['QR code and key'] = await foreignColours(page);

            await page.getByLabel('Authentication code').fill('000000');
            await page.getByRole('button', { name: 'Confirm' }).click();
            await page.getByText('The provided two factor authentication code was invalid.').waitFor();
            await useSentinelTheme(page);
            states['wrong code'] = await foreignColours(page);

            await page.getByLabel('Authentication code').fill(totp(key));
            await page.getByRole('button', { name: 'Confirm' }).click();
            await page.getByRole('heading', { name: 'Save your recovery codes' }).waitFor();
            await useSentinelTheme(page);
            states['recovery codes'] = await foreignColours(page);

            await page.goto('/user/two-factor');
            await useSentinelTheme(page);
            states.on = await foreignColours(page);

            for (const [state, colours] of Object.entries(states)) {
                expect(colours, `${state}: colours not from a token`).toEqual([]);
            }
        });
    });
}
