import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { foreignColours, makeStudentWithFolders, openStudentHome, THEMES, useSentinelTheme, useTheme } from './support.js';

/*
 * Studying by folder (docs/specs/vistud-2-blueprint.md, Phase 9): Abel keeps each week as a module, and in it a folder for
 * each lecture with its lab. A folder's page is a place to study (its own tabs, Study this), a module's Topics tab shows
 * its folders each with Study, and a session started on a folder is named for it and keeps to it.
 */

const sizes = { desktop: { width: 1440, height: 900 }, phone: { width: 390, height: 844 } };
const analyse = async (page) =>
    (await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze()).violations.map(
        (v) => `${v.id}: ${v.nodes.map((n) => n.target).join(' ')}`,
    );
const noSidewaysScroll = (page) => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth);
const tabs = (page) => page.getByRole('navigation', { name: 'This folder' });

test.use({ reducedMotion: 'reduce' });
test.describe.configure({ timeout: 90_000 });

async function openFolder(page, path = 'first', tab = null) {
    const seed = makeStudentWithFolders();
    await openStudentHome(page, seed.email);
    await page.goto(seed[path] + (tab ? `?tab=${tab}` : ''));
    await expect(page.getByRole('heading', { level: 1, name: path === 'first' ? 'Lecture 1 + Lab 1' : 'Lecture 2 + Lab 2', exact: true })).toBeVisible();
    await page.waitForLoadState('load');

    return seed;
}

for (const [name, viewport] of Object.entries(sizes)) {
    test(`a folder is a place to study: the module above it, Study this, its own tabs and topics: ${name}`, async ({ page }) => {
        await page.setViewportSize(viewport);
        await openFolder(page);

        // The module above the folder's name, once: the path line is only for a folder deeper down.
        await expect(page.locator('.section-header-eyebrow')).toHaveText('Week 1: OS Structure | Processes & Threads');
        await expect(page.getByRole('navigation', { name: 'Path' })).toHaveCount(0);
        await expect(page.getByRole('link', { name: 'Back to Week 1: OS Structure | Processes & Threads' })).toBeVisible();
        await expect(page.getByText('1 of 2 understood')).toBeVisible();
        for (const label of ['Topics', 'Files', 'Notes', 'Questions', 'Cards']) {
            await expect(tabs(page).getByRole('link', { name: new RegExp(`^${label}`) })).toBeVisible();
        }
        await expect(tabs(page).getByRole('link', { name: /^Topics/ })).toHaveAttribute('aria-current', 'page');
        // Only the folder's topics, and what the reader found in its lecture.
        await expect(page.getByRole('button', { name: 'Study The kernel' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Study System calls' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Study Scheduling' })).toHaveCount(0);
        await expect(page.getByRole('region', { name: 'New topics found' })).toContainText('1 new topic found in Lecture 1 - OS Structure.txt: Interrupts');
        expect(await noSidewaysScroll(page)).toBe(true);

        await page.getByRole('button', { name: /Study this/ }).click();
        for (const item of ['Whole folder', 'Pick a topic', 'Quiz me', 'Test me']) {
            await expect(page.locator('#study-menu').getByText(item, { exact: true })).toBeVisible();
        }
        await page.keyboard.press('Escape');

        // Its files are the folder's own.
        await tabs(page).getByRole('link', { name: /^Files/ }).click();
        await expect(page.getByRole('link', { name: 'Lecture 1 - OS Structure.txt' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Lab 01 - Command Line Basics.txt' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Lecture 2 - Processes and Threads.txt' })).toHaveCount(0);
    });
}

test('Whole folder starts a session named for the folder, which keeps to its topics and goes back to it', async ({ page }) => {
    const seed = await openFolder(page);

    await page.getByRole('button', { name: /Study this/ }).click();
    await page.locator('#study-menu').getByText('Whole folder', { exact: true }).click();
    await expect(page).toHaveURL(/\/sessions\//, { timeout: 15_000 });
    await expect(page.getByRole('heading', { level: 1, name: 'Lecture 1 + Lab 1', exact: true })).toBeVisible();
    await expect(page.getByText('Week 1: OS Structure | Processes & Threads › Lecture 1 + Lab 1')).toBeVisible();
    await expect(page.getByText('Topics in Lecture 1 + Lab 1').first()).toBeVisible();
    await expect(page.getByRole('button', { name: /The kernel/ }).first()).toBeVisible();
    await expect(page.getByRole('button', { name: /^Scheduling/ })).toHaveCount(0);

    await page.getByRole('link', { name: 'Back to Lecture 1 + Lab 1' }).click();
    await expect(page).toHaveURL(new RegExp(seed.first.replace(/[/]/g, '\\/') + '$'));
});

test("a module's Topics tab shows its folders, each with its own Study, and a topic moves between them", async ({ page }) => {
    const seed = makeStudentWithFolders();
    await openStudentHome(page, seed.email);
    await page.goto(seed.module);
    await expect(page.getByRole('heading', { level: 1, name: 'Week 1: OS Structure | Processes & Threads', exact: true })).toBeVisible();

    const first = page.getByRole('region', { name: 'Lecture 1 + Lab 1' });
    await expect(first).toContainText('1 of 2 understood');
    await expect(first.getByRole('button', { name: 'Study The kernel' })).toBeVisible();
    const second = page.getByRole('region', { name: 'Lecture 2 + Lab 2' });
    await expect(second.getByRole('button', { name: 'Study Scheduling' })).toBeVisible();

    // A topic is put in another folder from its sheet, and is shown there.
    await first.getByRole('button', { name: 'System calls', exact: true }).click();
    const place = page.locator('#topic-sheet').getByLabel('Module or folder');
    await expect(place).toBeVisible();
    await place.selectOption({ label: 'Lecture 2 + Lab 2' });
    await page.locator('#topic-sheet').getByRole('button', { name: 'Move', exact: true }).click();
    await expect(second.getByRole('button', { name: 'Study System calls' })).toBeVisible();
    await expect(first.getByRole('button', { name: 'Study System calls' })).toHaveCount(0);

    await page.getByRole('button', { name: 'Study Lecture 2 + Lab 2' }).click();
    await expect(page).toHaveURL(/\/sessions\//, { timeout: 15_000 });
    await expect(page.getByRole('heading', { level: 1, name: 'Lecture 2 + Lab 2', exact: true })).toBeVisible();
});

test("a folder's questions and cards are on its tabs, and its cards are reviewed on their own", async ({ page }) => {
    await openFolder(page, 'first', 'questions');

    await expect(page.getByRole('link', { name: 'Why does the kernel need two modes?' })).toBeVisible();
    await page.getByLabel('Ask a question about Lecture 1 + Lab 1').fill('What is a system call?');
    await page.getByRole('button', { name: 'Add', exact: true }).click();
    await expect(page.getByRole('link', { name: 'What is a system call?' })).toBeVisible();

    await tabs(page).getByRole('link', { name: /^Cards/ }).click();
    await expect(page.getByText('1 card, 1 due today')).toBeVisible();
    await expect(page.getByText('What does the kernel do?')).toBeVisible();
    await page.getByRole('link', { name: 'Review 1' }).click();
    await expect(page.getByText('What does the kernel do?')).toBeVisible();
    await expect(page.getByText('Lecture 1 + Lab 1').first()).toBeVisible();
});

for (const [name, viewport] of Object.entries(sizes)) {
    test(`every colour on a folder's tabs, a module's folders and a folder's session comes from a token: ${name}`, async ({ page }) => {
        await page.setViewportSize(viewport);
        const seed = await openFolder(page);
        const states = {};
        for (const tab of ['topics', 'files', 'questions', 'cards']) {
            await page.goto(`${seed.first}${tab === 'topics' ? '' : `?tab=${tab}`}`);
            await expect(tabs(page)).toBeVisible();
            await useSentinelTheme(page);
            states[`folder ${tab}`] = await foreignColours(page);
        }
        await page.goto(seed.module);
        await expect(page.getByRole('region', { name: 'Lecture 1 + Lab 1' })).toBeVisible();
        await useSentinelTheme(page);
        states['module topics by folder'] = await foreignColours(page);

        await page.getByRole('button', { name: 'Study Lecture 1 + Lab 1' }).click();
        await expect(page.getByRole('heading', { level: 1, name: 'Lecture 1 + Lab 1', exact: true })).toBeVisible({ timeout: 15_000 });
        await useSentinelTheme(page);
        states['session in a folder'] = await foreignColours(page);

        for (const [state, colours] of Object.entries(states)) {
            expect(colours, `${state}: colours not from a token`).toEqual([]);
        }
    });
}

for (const theme of THEMES) {
    test(`axe finds no violations on a folder's tabs, a module's folders and a folder's session: ${theme}`, async ({ page }) => {
        const seed = await openFolder(page);
        for (const tab of ['topics', 'files', 'notes', 'questions', 'cards']) {
            await page.goto(`${seed.first}${tab === 'topics' ? '' : `?tab=${tab}`}`);
            await expect(tabs(page)).toBeVisible();
            await useTheme(page, theme);
            expect(await analyse(page), `folder ${tab}`).toEqual([]);
        }
        await page.goto(seed.module);
        await expect(page.getByRole('region', { name: 'Lecture 1 + Lab 1' })).toBeVisible();
        await useTheme(page, theme);
        expect(await analyse(page), 'module topics by folder').toEqual([]);

        await page.getByRole('button', { name: 'Study Lecture 1 + Lab 1' }).click();
        await expect(page.getByRole('heading', { level: 1, name: 'Lecture 1 + Lab 1', exact: true })).toBeVisible({ timeout: 15_000 });
        await useTheme(page, theme);
        expect(await analyse(page), 'session in a folder').toEqual([]);
    });
}

test('a folder never scrolls sideways at 320 px, even with 200% text', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 800 });
    const seed = await openFolder(page);
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    for (const tab of ['topics', 'files', 'questions', 'cards']) {
        await page.goto(`${seed.first}${tab === 'topics' ? '' : `?tab=${tab}`}`);
        await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
        await expect(tabs(page)).toBeVisible();
        expect(await noSidewaysScroll(page), `folder ${tab}`).toBe(true);
    }
});

// Screenshots for review (PREVIEWS=1): a folder's page, a module's folders and a folder's session, on a computer and a phone.
for (const [size, viewport] of Object.entries({ desktop: { width: 1440, height: 900 }, mobile: { width: 390, height: 844 } })) {
    test(`folder previews ${size}`, async ({ page }) => {
        test.skip(!process.env.PREVIEWS, 'Set PREVIEWS=1 to regenerate the review screenshots.');
        const out = (name) => `docs/design/previews/${name}.png`;
        await page.setViewportSize(viewport);
        const seed = await openFolder(page);
        await useTheme(page, 'vistud-light');
        await page.screenshot({ path: out(`folder-${size}-vistud-light`), fullPage: true });
        await useTheme(page, 'vistud-dark');
        await page.screenshot({ path: out(`folder-${size}-vistud-dark`), fullPage: true });
        await page.goto(seed.module);
        await expect(page.getByRole('region', { name: 'Lecture 1 + Lab 1' })).toBeVisible();
        await useTheme(page, 'vistud-light');
        await page.screenshot({ path: out(`module-folders-${size}-vistud-light`), fullPage: true });
        await page.getByRole('button', { name: 'Study Lecture 1 + Lab 1' }).click();
        await expect(page.getByRole('heading', { level: 1, name: 'Lecture 1 + Lab 1', exact: true })).toBeVisible({ timeout: 15_000 });
        await useTheme(page, 'vistud-light');
        await page.screenshot({ path: out(`folder-session-${size}-vistud-light`), fullPage: size === 'mobile' });
    });
}
