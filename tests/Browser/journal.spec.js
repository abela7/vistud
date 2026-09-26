import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { foreignColours, makeStudentWithJournal, openStudentHome, THEMES, useSentinelTheme, useTheme } from './support.js';

/* The student's journal: a list, newest first, and one entry at a time (ADR 0003 §10.4). */

const desktop = { width: 1440, height: 900 };
const phone = { width: 390, height: 844 };

test.use({ reducedMotion: 'reduce' });

async function openJournal(page) {
    await openStudentHome(page, makeStudentWithJournal());
    await page.goto('/journal');
    await page.getByRole('heading', { name: 'Journal', exact: true }).waitFor();
    await page.waitForLoadState('load');
}

async function openCorrectAttempt(page) {
    await page.getByRole('link', { name: /^Attempt Correct/ }).click();
    await page.getByRole('heading', { name: /^Attempt/ }).waitFor();
    await page.waitForLoadState('load');
}

test('a student opens Journal, reads an attempt, and goes back', async ({ page }) => {
    await page.setViewportSize(desktop);
    await openStudentHome(page, makeStudentWithJournal());

    await page.locator('.app-sidebar').getByRole('link', { name: 'Journal' }).click();
    await page.getByRole('heading', { name: 'Journal', exact: true }).waitFor();
    await expect(page.locator('main').getByRole('listitem')).toHaveCount(5);
    await expect(page.locator('main').getByRole('listitem').first()).toContainText(/Attempt\s*Correct/);

    await openCorrectAttempt(page);
    await expect(page.getByText('LEFT JOIN orders')).toBeVisible();
    await expect(page.locator('.app-sidebar').getByRole('link', { name: 'Journal' })).toHaveAttribute('aria-current', 'page');

    await page.getByRole('link', { name: 'Journal', exact: true }).first().click();
    await page.waitForURL('**/journal');
});

for (const [name, viewport] of Object.entries({ desktop, phone })) {
    test(`every colour in the journal comes from a token: ${name}`, async ({ page }) => {
        await page.setViewportSize(viewport);
        await openJournal(page);
        await useSentinelTheme(page);
        const states = { list: await foreignColours(page) };
        await page.getByRole('link', { name: /^Attempt Incorrect/ }).hover();
        states['list, hover'] = await foreignColours(page);

        await openCorrectAttempt(page);
        await useSentinelTheme(page);
        states.entry = await foreignColours(page);

        for (const [state, colours] of Object.entries(states)) {
            expect(colours, `${state}: colours not from a token`).toEqual([]);
        }
    });
}

for (const theme of THEMES) {
    test(`axe finds no violations in the journal: ${theme}`, async ({ page }) => {
        const analyse = async () => (await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze()).violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target).join(' ')}`);

        await page.setViewportSize(desktop);
        await openJournal(page);
        await useTheme(page, theme);
        expect(await analyse()).toEqual([]);

        await openCorrectAttempt(page);
        await useTheme(page, theme);
        expect(await analyse()).toEqual([]);
    });
}

test('the journal never scrolls sideways at 320 px, even with 200% text', async ({ page }) => {
    await openJournal(page);
    await page.setViewportSize({ width: 320, height: 800 });
    for (const [where, open] of [['list', async () => {}], ['entry', () => openCorrectAttempt(page)]]) {
        await open();
        for (const zoom of ['100%', '200%']) {
            await page.evaluate((size) => (document.documentElement.style.fontSize = size), zoom);
            const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
            expect(overflow, `${where}, 320 px at ${zoom} text`).toBeLessThanOrEqual(0);
        }
    }
});
