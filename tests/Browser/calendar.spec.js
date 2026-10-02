import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { makeStudentWithModules, openStudentHome } from './support.js';

/* The calendar: a month of days with what is on each, the day picked, the agenda, filters (the owner's review, 2026-10-03). */

const devices = {
    computer: { viewport: { width: 1440, height: 900 } },
    phone: { viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true },
};

test.use({ reducedMotion: 'reduce' });

const isoDay = (date) => date.toISOString().slice(0, 10);

/** Biology with one assignment due today (the student's clock is UTC), then its Calendar, reached the way a student would. */
async function openCalendar(page, onPhone, title = 'Coursework 1: cell report') {
    await openStudentHome(page, makeStudentWithModules());
    await page.locator('main').getByRole('link', { name: 'Biology' }).click();
    await page.getByRole('heading', { level: 1, name: 'Biology' }).waitFor();
    const nav = onPhone ? page.locator('.app-tabbar') : page.locator('.app-sidebar');
    await nav.getByRole('link', { name: onPhone ? 'Tasks' : 'Assignments' }).click();
    await page.getByRole('heading', { level: 1, name: 'Assignments' }).waitFor();
    await page.locator('main').getByRole('link', { name: 'New assignment' }).first().click();
    await page.getByLabel('Name').fill(title);
    await page.getByLabel('Kind').selectOption({ label: 'Exam' });
    await page.getByLabel('Day').fill(isoDay(new Date()));
    await page.getByLabel('Time (optional)').fill('14:30');
    await page.getByRole('button', { name: 'Create' }).click();
    await page.getByRole('heading', { level: 1, name: title }).waitFor();
    // The Overview's Coming up has a link to it; on a computer the sidebar has it too.
    if (onPhone) {
        await page.locator('.app-tabbar').getByRole('link', { name: 'Overview' }).click();
        await page.locator('main').getByRole('link', { name: 'Calendar', exact: true }).click();
    } else {
        await page.locator('.app-sidebar').getByRole('link', { name: 'Calendar', exact: true }).click();
    }
    await page.getByRole('heading', { level: 1, name: 'Calendar' }).waitFor();
}

for (const [name, device] of Object.entries(devices)) {
    test.describe(`calendar on a ${name}`, () => {
        test.use(device);

        test(`a deadline is on its day, the day is picked, the agenda and the filters work (${name})`, async ({ page }) => {
            const onPhone = name === 'phone';
            await openCalendar(page, onPhone);
            const month = new Date().toLocaleString('en-GB', { month: 'long', year: 'numeric', timeZone: 'UTC' });
            await expect(page.getByRole('heading', { level: 2, name: month })).toBeVisible();

            // Today is marked, and what is due today is on it and listed under the grid.
            const today = page.locator('.cal-day.is-today');
            await expect(today).toHaveAttribute('aria-current', 'date');
            await expect(today).toHaveAttribute('aria-pressed', 'true');
            await expect(today).toContainText('1 thing');
            if (onPhone) {
                await expect(today.locator('.cal-dot')).toHaveCount(1);
                await expect(today.locator('.cal-items')).toBeHidden();
            } else {
                await expect(today.locator('.cal-chip')).toContainText('Coursework 1: cell report');
            }
            const panel = page.locator('#cal-panel');
            await expect(panel.getByRole('link', { name: 'Coursework 1: cell report' })).toBeVisible();
            await expect(panel).toContainText('14:30');
            await expect(panel).toContainText('Exam');

            // Another day is picked in the browser, and says there is nothing on it.
            const other = page.locator('.cal-cell:not(.is-outside) .cal-day:not(.is-today)').first();
            await other.click();
            await expect(other).toHaveAttribute('aria-pressed', 'true');
            await expect(today).toHaveAttribute('aria-pressed', 'false');
            await expect(panel.locator('section:visible')).toHaveCount(1);
            await expect(panel.locator('section:visible')).toContainText('Nothing on this day.');
            await expect(panel.getByRole('link', { name: 'Coursework 1: cell report' })).toBeHidden();

            // Nothing scrolls sideways.
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1)).toBe(true);

            // The agenda lists the days that have something.
            await page.getByRole('button', { name: 'Agenda', exact: true }).click();
            await expect(page).toHaveURL(/view=agenda/);
            await expect(page.locator('main').getByRole('link', { name: 'Coursework 1: cell report' })).toBeVisible();
            await expect(page.locator('.cal-today-pill')).toBeVisible();

            // A kind of thing is hidden, and shown again.
            await page.getByRole('button', { name: /^Deadlines/ }).click();
            await expect(page.getByRole('button', { name: /^Deadlines/ })).toHaveAttribute('aria-pressed', 'false');
            await expect(page.locator('main').getByRole('link', { name: 'Coursework 1: cell report' })).toHaveCount(0);
            await page.getByRole('button', { name: /^Deadlines/ }).click();
            await expect(page.locator('main').getByRole('link', { name: 'Coursework 1: cell report' })).toBeVisible();

            // Months are moved between, and Today comes back; the month is in the address.
            await page.getByRole('button', { name: 'Month', exact: true }).click();
            await page.getByRole('button', { name: 'Next month' }).click();
            await expect(page.getByRole('heading', { level: 2, name: month })).toHaveCount(0);
            await expect(page).toHaveURL(/m=\d{4}-\d{2}/);
            await page.getByRole('button', { name: 'Today' }).click();
            await expect(page.getByRole('heading', { level: 2, name: month })).toBeVisible();

            // The item opens its assignment.
            await panel.getByRole('link', { name: 'Coursework 1: cell report' }).click();
            await expect(page.getByRole('heading', { level: 1, name: 'Coursework 1: cell report' })).toBeVisible();
        });
    });
}

test('the calendar of every workspace says whose each thing is', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await openCalendar(page, false);
    await page.locator('.app-sidebar').getByRole('link', { name: 'All calendars' }).click();
    await page.getByRole('heading', { level: 1, name: 'Calendar' }).waitFor();
    await page.getByRole('button', { name: 'Agenda', exact: true }).click();
    const row = page.locator('.cal-entry').filter({ hasText: 'Coursework 1: cell report' });
    await expect(row).toContainText('Biology');
    await expect(row).toContainText('Exam');
});

test('axe finds no violations on the calendar, as a month and as an agenda', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await openCalendar(page, false);
    const analyse = async () => (await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze())
        .violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target).join(' ')}`);
    expect(await analyse()).toEqual([]);
    await page.getByRole('button', { name: 'Agenda', exact: true }).click();
    await page.locator('.cal-entry').first().waitFor();
    expect(await analyse()).toEqual([]);
});

test('the calendar never scrolls sideways at 320 px, even with 200% text', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 640 });
    await openCalendar(page, true, 'A coursework with a rather long name that goes on and on');
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
    await page.getByRole('button', { name: 'Agenda', exact: true }).click();
    await page.locator('.cal-entry').first().waitFor();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
});
