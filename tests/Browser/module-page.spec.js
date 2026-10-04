import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { foreignColours, makeStudentWithModulePage, openStudentHome, THEMES, useSentinelTheme, useTheme } from './support.js';

/*
 * The module page as the working surface (docs/specs/vistud-2-blueprint.md §3.5.3): tabs, what the reader found in the
 * files (Add all / Pick / Not these), files that say whether the AI has read them, Study this ▾, the topic sheet,
 * and the file page's "Read by the AI".
 */

const sizes = { desktop: { width: 1440, height: 900 }, phone: { width: 390, height: 844 } };
const analyse = async (page) =>
    (await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze()).violations.map(
        (v) => `${v.id}: ${v.nodes.map((n) => n.target).join(' ')}`,
    );
const noSidewaysScroll = (page) => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth);

test.use({ reducedMotion: 'reduce' });
test.describe.configure({ timeout: 90_000 });

async function openModule(page, tab = null) {
    const seed = makeStudentWithModulePage();
    await openStudentHome(page, seed.email);
    await page.goto(seed.module + (tab ? `?tab=${tab}` : ''));
    await expect(page.getByRole('heading', { level: 1, name: 'Week 3: CPU scheduling', exact: true })).toBeVisible();
    await page.waitForLoadState('load');

    return seed;
}

for (const [name, viewport] of Object.entries(sizes)) {
    test(`the module page has a context line, Study this and five tabs, and the topics say how they stand: ${name}`, async ({ page }) => {
        await page.setViewportSize(viewport);
        await openModule(page);

        await expect(page.getByText('5 Oct – 11 Oct · 1 of 2 understood')).toBeVisible();
        const tabs = page.getByRole('navigation', { name: 'This module' });
        for (const label of ['Topics', 'Files', 'Notes', 'Questions', 'Sessions']) {
            await expect(tabs.getByRole('link', { name: new RegExp(`^${label}`) })).toBeVisible();
        }
        await expect(tabs.getByRole('link', { name: /^Topics/ })).toHaveAttribute('aria-current', 'page');
        await expect(page.getByRole('button', { name: 'Study Processes' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Study Scheduling' })).toBeVisible();
        expect(await noSidewaysScroll(page)).toBe(true);

        await page.getByRole('button', { name: /Study this/ }).click();
        for (const item of ['Whole module', 'Pick a topic', 'Quiz me', 'Test me']) {
            await expect(page.locator('#study-menu').getByText(item, { exact: true })).toBeVisible();
        }
        await page.keyboard.press('Escape');
    });

    test(`what the reader found is one line: Add all puts the topics in the module: ${name}`, async ({ page }) => {
        await page.setViewportSize(viewport);
        await openModule(page);

        const bar = page.getByRole('region', { name: 'New topics found' });
        await expect(bar).toContainText('2 new topics found in Lecture 3.txt');
        await expect(bar).toContainText('Round robin, Priority scheduling');
        await bar.getByRole('button', { name: 'Pick' }).click();
        await expect(bar.getByRole('button', { name: 'Add Round robin' })).toBeVisible();
        await bar.getByRole('button', { name: 'Not Priority scheduling' }).click();
        await expect(bar).toContainText('1 new topic found');
        await bar.getByRole('button', { name: 'Add all' }).click();

        await expect(page.getByRole('region', { name: 'New topics found' })).toBeHidden();
        await expect(page.getByRole('button', { name: 'Study Round robin' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Study Priority scheduling' })).toHaveCount(0);
        expect(await noSidewaysScroll(page)).toBe(true);
    });

    test(`files say whether the AI has read them, and a topic opens on a sheet: ${name}`, async ({ page }) => {
        await page.setViewportSize(viewport);
        const seed = await openModule(page, 'files');

        await expect(page.getByText('Reading…').first()).toBeVisible();
        await expect(page.getByRole('button', { name: 'Read now' })).toHaveCount(1);
        await page.getByText('Read', { exact: true }).first().click();
        await expect(page.getByText('Scheduling policies and their trade-offs.')).toBeVisible();
        await expect(page.getByText('CPU scheduling · Round robin')).toBeVisible();
        expect(await noSidewaysScroll(page)).toBe(true);

        // Read now with no AI set up: a line that says what to do, and no run.
        await page.getByRole('button', { name: 'Read now' }).click();
        await expect(page.getByText(/in your AI settings first\./)).toBeVisible();

        // The Topics tab: a topic's name opens its sheet, where the student says where they stand.
        await page.goto(seed.module);
        await page.getByRole('button', { name: 'Scheduling', exact: true }).click();
        const sheet = page.locator('#topic-sheet');
        await expect(sheet.getByRole('heading', { name: 'Scheduling' })).toBeVisible();
        await expect(sheet.getByText('Where you stand')).toBeVisible();
        await sheet.getByText('Understood', { exact: true }).click();
        await expect(page.getByText('2 of 2 understood')).toBeVisible();
        expect(await noSidewaysScroll(page)).toBe(true);
    });

    test(`the file page says what the AI read and offers Read now when it has not: ${name}`, async ({ page }) => {
        await page.setViewportSize(viewport);
        const seed = makeStudentWithModulePage();
        await openStudentHome(page, seed.email);

        await page.goto(seed.file);
        const panel = page.getByRole('region', { name: 'Read by the AI' });
        await expect(panel).toContainText('Scheduling policies and their trade-offs.');
        await expect(panel).toContainText('CPU scheduling · Round robin');
        await panel.getByText('Outline').click();
        await expect(panel.getByText('Introduction')).toBeVisible();
        expect(await noSidewaysScroll(page)).toBe(true);

        await page.goto(seed.busy);
        await expect(page.getByRole('region', { name: 'Read by the AI' })).toContainText('Reading…');
    });

    test(`every colour on the module page and the file page comes from a token: ${name}`, async ({ page }) => {
        await page.setViewportSize(viewport);
        const seed = await openModule(page);
        await useSentinelTheme(page);
        const states = { topics: await foreignColours(page) };

        await page.getByRole('button', { name: /Study this/ }).click();
        states['study menu'] = await foreignColours(page);
        await page.keyboard.press('Escape');

        await page.getByRole('button', { name: 'Scheduling', exact: true }).click();
        await expect(page.locator('#topic-sheet')).toBeVisible();
        states['topic sheet'] = await foreignColours(page);
        await page.keyboard.press('Escape');

        await page.goto(`${seed.module}?tab=files`);
        await useSentinelTheme(page);
        await page.getByText('Read', { exact: true }).first().click();
        states.files = await foreignColours(page);

        await page.goto(seed.file);
        await useSentinelTheme(page);
        await expect(page.getByRole('region', { name: 'Read by the AI' })).toBeVisible();
        states['file page'] = await foreignColours(page);

        expect(Object.fromEntries(Object.entries(states).filter(([, found]) => found.length > 0)), 'colours not from a token').toEqual({});
    });

    for (const theme of THEMES) {
        test(`axe finds no violations on the module page, its files and the file page: ${name}, ${theme}`, async ({ page }) => {
            await page.setViewportSize(viewport);
            const seed = await openModule(page);
            await useTheme(page, theme);
            expect(await analyse(page)).toEqual([]);

            await page.getByRole('button', { name: 'Scheduling', exact: true }).click();
            await expect(page.locator('#topic-sheet').getByText('Where you stand')).toBeVisible();
            expect(await analyse(page)).toEqual([]);
            await page.keyboard.press('Escape');

            await page.goto(`${seed.module}?tab=files`);
            await useTheme(page, theme);
            await page.getByText('Read', { exact: true }).first().click();
            expect(await analyse(page)).toEqual([]);

            await page.goto(seed.file);
            await useTheme(page, theme);
            await expect(page.getByRole('region', { name: 'Read by the AI' })).toBeVisible();
            expect(await analyse(page)).toEqual([]);
        });
    }
}
