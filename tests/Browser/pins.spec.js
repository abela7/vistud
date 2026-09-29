import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { foreignColours, makeStudentWithNote, openStudentHome, pinNewNotes, THEMES, useSentinelTheme, useTheme } from './support.js';

/* Pinned notes: a button in the corner of every student page (docs/specs/workspaces.md, DESIGN.md "Pinned notes"). */

const desktop = { width: 1440, height: 900 };
const phone = { width: 390, height: 844 };
const small = { width: 320, height: 640 };

test.use({ reducedMotion: 'reduce' });
test.describe.configure({ timeout: 60_000 });

const dock = (page) => page.locator('[data-pin-dock]');
const openNotePage = async (page, url) => {
    await page.goto(url);
    await page.locator('[data-note-editor][data-ready]').waitFor();
};

/** The note's own page, Pin pressed. Waits for the pin to be kept. */
async function pinFromPage(page, url) {
    await openNotePage(page, url);
    await page.getByRole('button', { name: 'Pin', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Unpin', exact: true })).toBeVisible();
}

/** A student with the two notes, both pinned, on the workspace's page. */
async function twoPins(page) {
    const note = makeStudentWithNote();
    await openStudentHome(page, note.email);
    await pinFromPage(page, note.url);
    await pinFromPage(page, note.empty);
    await page.goto(`/workspaces/${note.workspace}`);
    await expect(dock(page).getByRole('button', { name: /Pinned notes/ })).toBeVisible();
    return note;
}

const notOverflowing = (page) => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth);
const within = (box, width, height) => box.x >= 0 && box.y >= 0 && box.x + box.width <= width && box.y + box.height <= height;

test('a pinned note is a button in the corner of every page, and opens in a window of its own', async ({ page }) => {
    await page.setViewportSize(desktop);
    const note = makeStudentWithNote();
    await openStudentHome(page, note.email);
    await openNotePage(page, note.url);
    await expect(dock(page)).toHaveCount(0);

    // Pin: the note's own page never shows its button, and says where it went.
    await page.getByRole('button', { name: 'Pin', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Pinned. Its button is now in the corner of every page.' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Unpin', exact: true })).toBeVisible();
    await expect(dock(page)).toHaveCount(0);

    // Any other page has it, at the bottom right.
    await page.goto(`/workspaces/${note.workspace}`);
    const pill = dock(page).getByRole('link', { name: /Pinned note: Mitosis vs meiosis/ });
    await expect(pill).toBeVisible();
    const box = await pill.boundingBox();
    expect(Math.abs(desktop.width - (box.x + box.width) - 16)).toBeLessThanOrEqual(2);
    expect(Math.abs(desktop.height - (box.y + box.height) - 16)).toBeLessThanOrEqual(2);

    // It stays as the page changes without reloading.
    await page.getByRole('link', { name: 'Modules', exact: true }).first().click();
    await page.waitForURL(/\/modules$/);
    await expect(pill).toBeVisible();

    // A press opens the note beside the page: the page itself doesn't move.
    const before = page.url();
    const opening = page.waitForEvent('popup');
    await pill.click();
    const win = await opening;
    await win.waitForURL(/\?window=1$/);
    await win.locator('[data-note-editor][data-ready]').waitFor();
    await expect(win.getByLabel('Title')).toHaveValue('Mitosis vs meiosis');
    // The window is the note alone: no corner button there.
    await expect(win.locator('[data-pin-dock]')).toHaveCount(0);
    expect(page.url()).toBe(before);
    await win.close();

    // Unpinned from its page, the corner is empty again.
    await openNotePage(page, note.url);
    await page.getByRole('button', { name: 'Unpin', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Pin', exact: true })).toBeVisible();
    await page.goto(`/workspaces/${note.workspace}`);
    await expect(dock(page)).toHaveCount(0);
});

test('a note is pinned from its row in a list, and unpinned there too', async ({ page }) => {
    await page.setViewportSize(desktop);
    const note = makeStudentWithNote();
    await openStudentHome(page, note.email);
    await page.goto(note.url);
    await page.locator('[data-note-editor][data-ready]').waitFor();
    await page.getByRole('navigation', { name: 'Where this note is' }).getByRole('link', { name: 'Week 2: Cell division' }).click();
    await page.waitForURL(/\/modules\/[^/]+$/);

    await page.getByRole('button', { name: 'Actions for Mitosis vs meiosis' }).click();
    await page.getByRole('button', { name: 'Pin to the corner' }).click();
    await expect(page.getByRole('status').filter({ hasText: '“Mitosis vs meiosis” is pinned.' })).toBeVisible();
    await expect(dock(page).getByRole('link', { name: /Pinned note: Mitosis vs meiosis/ })).toBeVisible();

    // Opening it here: it's open, so its button leaves the corner.
    await page.getByRole('link', { name: 'Mitosis vs meiosis', exact: true }).click();
    await page.locator('[data-note-editor][data-ready]').waitFor();
    await expect(dock(page)).toHaveCount(0);
    await page.goBack();
    await page.waitForURL(/\/modules\/[^/]+$/);
    await expect(dock(page).getByRole('link', { name: /Pinned note: Mitosis vs meiosis/ })).toBeVisible();

    await page.getByRole('button', { name: 'Actions for Mitosis vs meiosis' }).click();
    await page.getByRole('button', { name: 'Unpin', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: '“Mitosis vs meiosis” is unpinned.' })).toBeVisible();
    await expect(dock(page)).toHaveCount(0);
});

test('a row\'s menu follows a pin taken out of the corner, and the corner survives Back after selecting', async ({ page }) => {
    await page.setViewportSize(desktop);
    const note = await twoPins(page);
    await page.goto(`/workspaces/${note.workspace}/modules`);
    await page.getByRole('link', { name: /Week 2: Cell division/ }).click();
    await page.waitForURL(/\/modules\/[^/]+$/);

    // The note is pinned, so its row offers Unpin; take it out of the corner's list and the row offers Pin again.
    await page.getByRole('button', { name: 'Actions for Mitosis vs meiosis' }).click();
    await expect(page.getByRole('button', { name: 'Unpin', exact: true })).toBeVisible();
    await page.keyboard.press('Escape');
    await dock(page).getByRole('button', { name: /Pinned notes/ }).click();
    await page.locator('#pin-menu').getByRole('button', { name: 'Unpin Mitosis vs meiosis' }).click();
    await expect(page.getByRole('status').filter({ hasText: '“Mitosis vs meiosis” is unpinned.' })).toBeVisible();
    await page.keyboard.press('Escape');
    await page.getByRole('button', { name: 'Actions for Mitosis vs meiosis' }).click();
    await expect(page.getByRole('button', { name: 'Pin to the corner' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Unpin', exact: true })).toHaveCount(0);

    // Leave a page while selecting rows, then come back to it: the corner is there, and not marked as selecting.
    await page.keyboard.press('Escape');
    await page.getByRole('button', { name: 'Select' }).click();
    await expect(page.locator('html')).toHaveAttribute('data-selecting', '');
    await page.getByRole('link', { name: 'Overview', exact: true }).first().click();
    await page.waitForURL(new RegExp(`/workspaces/${note.workspace}$`));
    await expect(page.locator('html')).not.toHaveAttribute('data-selecting', '');
    await page.goBack();
    await page.waitForURL(/\/modules\/[^/]+$/);
    await expect(page.locator('html')).not.toHaveAttribute('data-selecting', '');
    await expect(dock(page).getByRole('link', { name: /Pinned note: Untitled note/ })).toBeVisible();
});

test('several pins are one button with a list; each opens its note, or is unpinned', async ({ page }) => {
    await page.setViewportSize(desktop);
    await twoPins(page);
    const button = dock(page).getByRole('button', { name: /Pinned notes/ });
    await expect(button).toContainText('2');
    const menu = page.locator('#pin-menu');
    await expect(menu).toBeHidden();

    await button.click();
    await expect(menu).toBeVisible();
    await expect(button).toHaveAttribute('aria-expanded', 'true');
    await expect(menu.getByRole('link')).toHaveCount(2);
    await expect(menu.getByRole('link', { name: /Mitosis vs meiosis/ })).toContainText('Biology');
    // The list opens above the button, inside the window.
    const [listBox, buttonBox] = [await menu.boundingBox(), await button.boundingBox()];
    expect(listBox.y + listBox.height).toBeLessThanOrEqual(buttonBox.y);
    expect(within(listBox, desktop.width, desktop.height)).toBe(true);

    // Esc closes it and gives the button focus back; so does a press outside.
    await page.keyboard.press('Escape');
    await expect(menu).toBeHidden();
    await expect(button).toBeFocused();
    await button.click();
    await page.getByRole('heading', { level: 1 }).click();
    await expect(menu).toBeHidden();

    // A row opens its note in a window of its own, and the list closes.
    await button.click();
    const opening = page.waitForEvent('popup');
    await menu.getByRole('link', { name: /Untitled note/ }).click();
    const win = await opening;
    await win.waitForURL(/\?window=1$/);
    await win.close();
    await expect(menu).toBeHidden();

    // Unpin one: one is left, and it's a button that opens its note.
    await button.click();
    await menu.getByRole('button', { name: 'Unpin Untitled note' }).click();
    await expect(page.getByRole('status').filter({ hasText: '“Untitled note” is unpinned.' })).toBeVisible();
    await expect(dock(page).getByRole('link', { name: /Pinned note: Mitosis vs meiosis/ })).toBeVisible();
    await expect(dock(page).getByRole('button')).toHaveCount(0);
});

test('at most eight notes can be pinned; the next one says so', async ({ page }) => {
    await page.setViewportSize(desktop);
    const note = makeStudentWithNote();
    await openStudentHome(page, note.email);
    // Eight, made through the real service, the way a student would have pinned them one by one.
    pinNewNotes(note.email, 8);

    await openNotePage(page, note.url);
    await page.getByRole('button', { name: 'Pin', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'You can pin 8 notes. Unpin one to pin another.' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Pin', exact: true })).toBeVisible();

    // The list shows all eight, scrolling inside the window if it must.
    await page.goto(`/workspaces/${note.workspace}`);
    await dock(page).getByRole('button', { name: /Pinned notes/ }).click();
    await expect(page.locator('#pin-menu').getByRole('link')).toHaveCount(8);
    expect(within(await page.locator('#pin-menu').boundingBox(), desktop.width, desktop.height)).toBe(true);
});

for (const size of [phone, small]) {
    test(`on a phone (${size.width} px) the button is round, above the tab bar, and nothing scrolls sideways`, async ({ page }) => {
        await page.setViewportSize(size);
        const note = await twoPins(page);
        const tabbar = page.getByRole('navigation', { name: /sections/ });
        await expect(tabbar).toBeVisible();
        const button = dock(page).getByRole('button', { name: /Pinned notes/ });
        const box = await button.boundingBox();
        const bar = await tabbar.boundingBox();
        expect([Math.round(box.width), Math.round(box.height)]).toEqual([48, 48]);
        // Clear of the tab bar, at the right edge.
        expect(box.y + box.height).toBeLessThanOrEqual(bar.y);
        expect(Math.abs(size.width - (box.x + box.width) - 16)).toBeLessThanOrEqual(2);
        expect(await notOverflowing(page)).toBe(true);

        // Its name is still there for a screen reader, and the count on its corner.
        await expect(button).toContainText('2');
        await button.click();
        const menu = page.locator('#pin-menu');
        await expect(menu).toBeVisible();
        expect(within(await menu.boundingBox(), size.width, size.height)).toBe(true);
        expect(await notOverflowing(page)).toBe(true);
        await menu.getByRole('button', { name: 'Unpin Untitled note' }).click();

        // One left: a round button that opens its note (a tab, on a phone).
        const pill = dock(page).getByRole('link', { name: /Pinned note: Mitosis vs meiosis/ });
        await expect(pill).toBeVisible();
        const one = await pill.boundingBox();
        expect([Math.round(one.width), Math.round(one.height)]).toEqual([48, 48]);
        await expect(pill).toHaveAttribute('target', /^vistud-note-/);
        expect(note.url).toBeTruthy();
    });
}

for (const size of [desktop, phone]) {
    test(`the foot of a page is above the button (${size.width} px)`, async ({ page }) => {
        await page.setViewportSize(size);
        await twoPins(page);
        await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
        const button = dock(page).getByRole('button', { name: /Pinned notes/ });
        // Where the page's own content ends: the room kept for the button starts there.
        const contentEnd = await page.locator('.pin-clearance').evaluate((el) => el.getBoundingClientRect().top);
        expect(contentEnd).toBeLessThanOrEqual((await button.boundingBox()).y);
    });
}

test('the button steps aside for full screen, for selecting rows, and for print', async ({ page }) => {
    await page.setViewportSize(desktop);
    const note = await twoPins(page);
    const pill = dock(page).getByRole('button', { name: /Pinned notes/ });

    // Selecting rows: the selection bar takes the foot of the page.
    await page.getByRole('link', { name: 'Modules', exact: true }).first().click();
    await page.waitForURL(/\/modules$/);
    await expect(pill).toBeVisible();
    await page.getByRole('button', { name: 'Select' }).click();
    await expect(pill).toBeHidden();
    await page.keyboard.press('Escape');
    await expect(pill).toBeVisible();

    // Print: only the note's own page counts, and no page carries the button.
    await page.emulateMedia({ media: 'print' });
    await expect(dock(page)).toBeHidden();
    await page.emulateMedia({ media: 'screen' });

    // Full screen: a note on top of everything; the button under it can't be pressed.
    await openNotePage(page, note.url);
    const single = dock(page).getByRole('link', { name: /Pinned note: Untitled note/ });
    await expect(single).toBeVisible();
    await page.getByRole('button', { name: 'Full screen' }).click();
    await expect(page.locator('[data-note-page][data-focus]')).toBeVisible();
    const covered = await dock(page).evaluate((el) => {
        const r = el.getBoundingClientRect();
        return !el.contains(document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2));
    });
    expect(covered).toBe(true);
});

for (const theme of THEMES) {
    test(`axe finds no violations with pinned notes: ${theme}`, async ({ page }) => {
        await page.setViewportSize(desktop);
        await twoPins(page);
        await useTheme(page, theme);
        const check = async (state) => {
            const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
            expect(results.violations.map((v) => `${state}: ${v.id}: ${v.nodes.map((n) => n.target).join(' ')}`)).toEqual([]);
        };
        await check('closed');
        await dock(page).getByRole('button', { name: /Pinned notes/ }).click();
        await page.locator('#pin-menu').waitFor();
        await check('list open');
    });
}

test('one pin and a list use only theme colours', async ({ page }) => {
    await page.setViewportSize(desktop);
    await twoPins(page);
    await useSentinelTheme(page);
    const states = { 'list closed': await foreignColours(page) };
    await dock(page).getByRole('button', { name: /Pinned notes/ }).click();
    await page.locator('#pin-menu').waitFor();
    await page.locator('#pin-menu .pin-open').first().hover();
    states['list open'] = await foreignColours(page);
    await page.locator('#pin-menu').getByRole('button', { name: /^Unpin/ }).first().click();
    await dock(page).getByRole('link', { name: /Pinned note/ }).waitFor();
    states['one pin'] = await foreignColours(page);
    for (const [state, colours] of Object.entries(states)) {
        expect(colours, `${state}: colours not from a token`).toEqual([]);
    }
});

test('very large text and a narrow window: the list and the button stay inside', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 568 });
    await twoPins(page);
    // 200% text, as a low-vision student would set it.
    await page.addStyleTag({ content: 'html { font-size: 200%; }' });
    const button = dock(page).getByRole('button', { name: /Pinned notes/ });
    await button.click();
    const menu = page.locator('#pin-menu');
    await expect(menu).toBeVisible();
    const [menuBox, buttonBox, barBox] = [await menu.boundingBox(), await button.boundingBox(), await page.getByRole('navigation', { name: /sections/ }).boundingBox()];
    expect(within(menuBox, 320, 568), JSON.stringify({ menuBox, buttonBox, barBox })).toBe(true);
    expect(within(buttonBox, 320, 568)).toBe(true);
    expect(await notOverflowing(page)).toBe(true);
});
