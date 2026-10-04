import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { foreignColours, makeStudentAccount, THEMES, useSentinelTheme, useTheme } from './support.js';

/*
 * A student's AI settings (docs/specs/vistud-2-blueprint.md Phase 0): a model for each of the three roles with
 * one line each, what the month has cost by role, and the trust toggles; the page starts with <x-page>.
 */

const desktop = { width: 1440, height: 900 };
const phone = { width: 390, height: 844 };

test.use({ reducedMotion: 'reduce' });

async function openSettings(page) {
    const email = makeStudentAccount();
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password', { exact: true }).fill('password-for-tests');
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.getByRole('heading', { name: 'My courses' }).waitFor();
    await page.goto('/settings?part=ai');
    await page.getByRole('heading', { name: 'Settings', level: 1 }).waitFor();
}

for (const [name, viewport] of Object.entries({ desktop, phone })) {
    test(`the page shows the three roles, the usage and the toggles: ${name}`, async ({ page }) => {
        await page.setViewportSize(viewport);
        await openSettings(page);

        await expect(page.getByText('Your key, your models and what they cost.')).toBeVisible();
        await expect(page.getByRole('link', { name: 'Back to All courses' })).toBeVisible();
        for (const label of ['Tutor', 'Reader (optional)', 'Helper (optional)']) {
            await expect(page.getByLabel(label, { exact: true })).toBeVisible();
        }
        await expect(page.getByText('Teaches in sessions.')).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Usage this month' })).toBeVisible();
        for (const role of ['tutor', 'reader', 'helper']) {
            await expect(page.locator(`[data-usage-role="${role}"]`)).toHaveText('$0.00');
        }
        for (const toggle of ['Ask me before adding or switching topics', 'Let the tutor mark topics', 'Read my files automatically', 'I use another AI by copy-paste']) {
            await expect(page.getByLabel(toggle)).toBeVisible();
        }
        await expect(page.getByLabel('I use another AI by copy-paste')).not.toBeChecked();
        await expect(page.getByLabel('Let the tutor mark topics')).toBeChecked();

        // A toggle can be reached and changed with the keyboard.
        await page.getByLabel('I use another AI by copy-paste').focus();
        await page.keyboard.press('Space');
        await expect(page.getByLabel('I use another AI by copy-paste')).toBeChecked();

        // No horizontal scrolling.
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
    });

    test(`every colour on the page comes from a token: ${name}`, async ({ page }) => {
        await page.setViewportSize(viewport);
        await openSettings(page);
        await useSentinelTheme(page);

        expect(await foreignColours(page), `AI settings ${name}: colours not from a token`).toEqual([]);
    });

    for (const theme of THEMES) {
        test(`axe finds no violations on the page: ${name}, ${theme}`, async ({ page }) => {
            await page.setViewportSize(viewport);
            await openSettings(page);
            await useTheme(page, theme);

            const results = await new AxeBuilder({ page }).analyze();
            expect(results.violations, JSON.stringify(results.violations.map((v) => [v.id, v.nodes.map((n) => n.target)]))).toEqual([]);
        });
    }
}
