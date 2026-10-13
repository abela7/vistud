import { test, expect } from '@playwright/test';
import { makeStudentWithEverything, openStudentHome } from './support.js';

/*
 * Rows that slide sideways (resources/js/strips.js): a module's tabs are wider than a 320 px screen, and the row has to
 * say so, move with a mouse as well as a finger, and start with the current tab in sight.
 */

test.use({ viewport: { width: 320, height: 640 }, reducedMotion: 'reduce' });

const scrollLeft = (strip) => strip.evaluate((el) => el.scrollLeft);

test('a row of tabs wider than the screen says so, and moves with the wheel and a mouse drag', async ({ page }) => {
    const student = makeStudentWithEverything();
    await openStudentHome(page, student.email);
    await page.goto(`/courses/${student.workspace}/modules/${student.module}`);

    const tabs = page.locator('nav.page-tabs');
    await expect(tabs).toBeVisible();
    expect(await tabs.evaluate((el) => el.scrollWidth - el.clientWidth), 'the tabs need more room than 320 px').toBeGreaterThan(0);

    // At the start: more to the right, nothing to the left.
    await expect(tabs).toHaveAttribute('data-more', /^(end|both)$/);
    expect(await scrollLeft(tabs)).toBeLessThan(2);

    // The wheel, over the row, moves it sideways.
    const box = await tabs.boundingBox();
    const [x, y] = [box.x + box.width / 2, box.y + box.height / 2];
    await page.mouse.move(x, y);
    await page.mouse.wheel(0, 200);
    await expect.poll(() => scrollLeft(tabs)).toBeGreaterThan(0);
    await expect(tabs).toHaveAttribute('data-more', /^(start|both)$/);

    // A drag moves it back, and is not taken for a click on the tab under the mouse.
    const url = page.url();
    const before = await scrollLeft(tabs);
    await page.mouse.move(x, y);
    await page.mouse.down();
    await page.mouse.move(x + 90, y, { steps: 6 });
    await page.mouse.up();
    expect(await scrollLeft(tabs)).toBeLessThan(before);
    await page.waitForTimeout(200);
    expect(page.url()).toBe(url);

    // All the way along: the end is reached, and only the start has more.
    await tabs.evaluate((el) => { el.scrollLeft = el.scrollWidth; });
    await expect(tabs).toHaveAttribute('data-more', 'start');
});

test('a late tab is in sight when its page opens', async ({ page }) => {
    const student = makeStudentWithEverything();
    await openStudentHome(page, student.email);
    await page.goto(`/courses/${student.workspace}/modules/${student.module}`);

    const href = await page.locator('nav.page-tabs a.page-tab').last().getAttribute('href');
    await page.goto(href);

    const current = page.locator('nav.page-tabs [aria-current="page"]');
    await expect(current).toBeVisible();
    const inSight = await current.evaluate((el) => {
        const row = el.closest('.page-tabs').getBoundingClientRect();
        const box = el.getBoundingClientRect();
        return box.left >= row.left - 1 && box.right <= row.right + 1;
    });
    expect(inSight, 'the current tab is inside the row').toBe(true);
});
