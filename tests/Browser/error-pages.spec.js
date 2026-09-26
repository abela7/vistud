import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { foreignColours, makeStudentAccount, THEMES, useSentinelTheme, useTheme } from './support.js';

/* Styled 403 and 404 pages. Neither reveals why the request was refused. */

const desktop = { width: 1440, height: 900 };
const phone = { width: 390, height: 844 };

test.use({ reducedMotion: 'reduce' });

async function openForbidden(page) {
    const email = makeStudentAccount();
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password', { exact: true }).fill('password-for-tests');
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.getByRole('heading', { name: /^Welcome back/ }).waitFor();
    await page.goto('/admin');
    await page.getByRole('heading', { name: "You can't open this page" }).waitFor();
}

async function openMissing(page) {
    await page.goto('/no-such-page');
    await page.getByRole('heading', { name: 'Page not found' }).waitFor();
}

for (const [label, open] of Object.entries({ forbidden: openForbidden, missing: openMissing })) {
    for (const [name, viewport] of Object.entries({ desktop, phone })) {
        test(`every colour on the ${label} page comes from a token: ${name}`, async ({ page }) => {
            await page.setViewportSize(viewport);
            await open(page);
            await useSentinelTheme(page);

            expect(await foreignColours(page), `${label} ${name}: colours not from a token`).toEqual([]);
        });
    }

    for (const theme of THEMES) {
        test(`axe finds no violations on the ${label} page: ${theme}`, async ({ page }) => {
            await page.setViewportSize(desktop);
            await open(page);
            await useTheme(page, theme);
            const violations = (await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze()).violations;

            expect(violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target).join(' ')}`)).toEqual([]);
        });
    }

    test(`the ${label} page never scrolls sideways at 320 px, even with 200% text`, async ({ page }) => {
        await open(page);
        await page.setViewportSize({ width: 320, height: 800 });
        for (const zoom of ['100%', '200%']) {
            await page.evaluate((size) => (document.documentElement.style.fontSize = size), zoom);
            const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
            expect(overflow, `${label} 320 px at ${zoom} text`).toBeLessThanOrEqual(0);
        }
    });
}
