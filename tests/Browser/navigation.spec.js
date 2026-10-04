import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { foreignColours, makeStudentWithNote, openStudentHome, THEMES, useSentinelTheme, useTheme } from './support.js';

/* The way back up (the owner's review, 2026-09-28): every page but Home has a Back link to the page above it. */

test.use({ reducedMotion: 'reduce' });

const back = (page) => page.getByRole('link', { name: /^Back to / });

for (const [device, size] of [['desktop', { width: 1440, height: 900 }], ['phone', { width: 390, height: 844 }]]) {
    test(`Back climbs from a note in a folder all the way to Home: ${device}`, async ({ page }) => {
        await page.setViewportSize(size);
        const note = makeStudentWithNote();
        await openStudentHome(page, note.email);
        await page.goto(note.empty);
        await page.locator('[data-note-editor][data-ready]').waitFor();

        // A note in the Labs folder goes back to the folder, then up through its module, Modules and the course's Home to All courses.
        const steps = [
            ['Labs', /\/folders\//],
            [null, /\/modules\/[^/]+$/],
            ['Modules', /\/modules$/],
            ['Home', new RegExp(`/courses/${note.workspace}$`)],
            ['All courses', /\/$/],
        ];
        for (const [name, url] of steps) {
            const link = back(page);
            await expect(link).toBeVisible();
            if (name) await expect(link).toHaveAccessibleName(`Back to ${name}`);
            const box = await link.boundingBox();
            expect(box.height).toBeGreaterThanOrEqual(device === 'phone' ? 44 : 36);
            expect(box.x + box.width).toBeLessThanOrEqual(size.width);
            await link.click();
            await expect(page).toHaveURL(url);
        }
        // Home is the top: nothing above it.
        await expect(back(page)).toHaveCount(0);
    });
}

test('coming from the page above, Back returns to it as it was left, scrolled to the same place', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 400 });
    const note = makeStudentWithNote();
    await openStudentHome(page, note.email);
    await page.goto(note.url);
    const modulePage = await back(page).getAttribute('href');
    await page.goto(modulePage);
    const scrolled = await page.evaluate(() => {
        window.scrollTo(0, Math.min(150, document.documentElement.scrollHeight - window.innerHeight));
        return window.scrollY;
    });
    expect(scrolled).toBeGreaterThan(0);
    await page.getByRole('link', { name: 'Mitosis vs meiosis' }).first().evaluate((a) => a.click());
    await page.locator('[data-note-editor][data-ready]').waitFor();
    await back(page).click();
    await expect(page).toHaveURL(modulePage);
    await expect.poll(() => page.evaluate(() => Math.round(window.scrollY))).toBe(Math.round(scrolled));
});

for (const theme of THEMES) {
    test(`the Back link: colours from tokens and axe finds nothing: ${theme}`, async ({ page }) => {
        const note = makeStudentWithNote();
        await openStudentHome(page, note.email);
        await page.goto(`/courses/${note.workspace}/modules`);
        await useTheme(page, theme);
        await back(page).hover();
        const results = await new AxeBuilder({ page }).include('.back-link').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
        expect(results.violations.map((v) => v.id)).toEqual([]);
        await useSentinelTheme(page);
        expect(await foreignColours(page)).toEqual([]);
    });
}
