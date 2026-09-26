import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { loginToChallenge, THEMES, useTheme } from './support.js';

/* Accessibility of the login screen in every built-in theme (DESIGN.md §9). */

for (const theme of THEMES) {
    for (const [name, viewport] of Object.entries({ desktop: { width: 1440, height: 900 }, mobile: { width: 390, height: 844 } })) {
        test(`axe finds no violations: ${theme}, ${name}`, async ({ page }) => {
            await page.setViewportSize(viewport);
            await page.goto('/login');
            await useTheme(page, theme);
            const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
            expect(results.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target).join(' ')}`)).toEqual([]);
        });
    }
}

test('the keyboard reaches every control in order, each with a visible focus ring', async ({ page }) => {
    await page.goto('/login');
    const reached = [];
    // Email has focus on arrival (autofocus); Tab walks on from there.
    for (let i = 0; i < 6; i++) {
        if (i > 0) await page.keyboard.press('Tab');
        reached.push(await page.evaluate(() => {
            const el = document.activeElement;
            const name = el.getAttribute('aria-label') ?? el.labels?.[0]?.innerText.trim() ?? el.innerText.trim();
            // A visually hidden radio shows its ring on its label (components.css).
            const shown = el.closest('.segmented-option') ?? el;
            const style = getComputedStyle(shown);
            const ring = style.outlineStyle === 'solid' && parseFloat(style.outlineWidth) >= 2;
            return ring ? name : `${name} (no focus ring)`;
        }));
    }
    // One stop for the radio group: arrow keys move within it.
    expect(reached.slice(0, 6)).toEqual(['Email', 'Password', 'Show password', 'Keep me logged in on this device', 'Log in', 'System']);
});

test('touch targets are at least 44 px on a phone', async ({ browser }) => {
    const context = await browser.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true });
    const page = await context.newPage();
    await page.goto('/login');
    const small = await page.evaluate(() => [...document.querySelectorAll('input:not(.sr-only):not([type=checkbox]):not([type=hidden]), button, .segmented-option, .checkbox-row')]
        .map((el) => ({ el: el.textContent.trim() || el.getAttribute('aria-label') || el.name, rect: el.getBoundingClientRect() }))
        .filter(({ rect }) => rect.height < 44)
        .map(({ el, rect }) => `${el}: ${Math.round(rect.height)} px`));
    expect(small).toEqual([]);
    await context.close();
});

for (const theme of THEMES) {
    for (const [name, viewport] of Object.entries({ desktop: { width: 1440, height: 900 }, mobile: { width: 390, height: 844 } })) {
        test(`two-factor challenge axe finds no violations: ${theme}, ${name}`, async ({ page }) => {
            await page.setViewportSize(viewport);
            await loginToChallenge(page);
            await useTheme(page, theme);
            const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
            expect(results.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target).join(' ')}`)).toEqual([]);
        });
    }
}

test('the two-factor challenge never scrolls sideways at 320 px', async ({ page }) => {
    await loginToChallenge(page);
    await page.getByRole('button', { name: 'Use a recovery code instead' }).click();
    await page.getByLabel('Recovery code').fill(`recovery.${'x'.repeat(80)}`);
    for (const zoom of ['100%', '200%']) {
        await page.setViewportSize({ width: 320, height: 800 });
        await page.evaluate((size) => (document.documentElement.style.fontSize = size), zoom);
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        expect(overflow, `320 px at ${zoom} text`).toBeLessThanOrEqual(0);
    }
});

test('long content and 200% text never scroll the page sideways', async ({ page }) => {
    const email = `a.very.long.address.that.keeps.going.${'x'.repeat(60)}@example.test`;
    for (const width of [320, 390]) {
        await page.setViewportSize({ width, height: 800 });
        await page.goto('/login');
        await page.getByLabel('Email').fill(email);
        await page.getByLabel('Password', { exact: true }).fill('not-the-password');
        await page.getByRole('button', { name: 'Log in' }).click();
        await page.waitForURL('**/login');
        for (const zoom of ['100%', '200%']) {
            await page.evaluate((size) => (document.documentElement.style.fontSize = size), zoom);
            const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
            expect(overflow, `${width} px at ${zoom} text`).toBeLessThanOrEqual(0);
        }
    }
});
