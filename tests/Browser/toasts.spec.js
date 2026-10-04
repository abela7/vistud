import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { foreignColours, makeStudentWithSession, openStudentHome, useSentinelTheme } from './support.js';

/* Passing notices close themselves, or with their × (resources/js/toasts.js). */

test.use({ reducedMotion: 'reduce' });
test.describe.configure({ timeout: 60_000 });

async function openOverview(page) {
    const student = makeStudentWithSession();
    await openStudentHome(page, student.email);
    await page.goto(`/courses/${student.workspace}`);
    await page.getByRole('heading', { level: 1, name: 'Databases' }).waitFor();
    await page.waitForLoadState('load');
}

async function saveInstructions(page, text) {
    await page.getByRole('button', { name: 'More for Databases' }).click();
    await page.getByRole('button', { name: 'Instructions for the AI' }).click();
    await page.locator('#instructions-dialog').getByLabel(/About you/).fill(text);
    await page.locator('#instructions-dialog').getByRole('button', { name: 'Save' }).click();
    await page.locator('#instructions-dialog').waitFor({ state: 'hidden' });
}

test('a notice closes itself after a few seconds, or with its ×, and shows again when it happens again', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await openOverview(page);
    const toasts = page.getByRole('status').filter({ has: page.locator('.toast') });

    await saveInstructions(page, 'Explain with everyday examples.');
    await expect(toasts.getByText('Instructions saved.')).toBeVisible();
    await expect(page.locator('.toast')).toHaveCount(0, { timeout: 9000 });

    await saveInstructions(page, 'One step at a time.');
    await expect(toasts.getByText('Instructions saved.')).toBeVisible();
    await toasts.getByRole('button', { name: 'Dismiss' }).click();
    await expect(page.locator('.toast')).toHaveCount(0);
});

test('a notice stays while the pointer is on it', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await openOverview(page);
    await saveInstructions(page, 'Explain with everyday examples.');
    await page.locator('.toast').hover();
    await page.waitForTimeout(7000);
    await expect(page.locator('.toast')).toBeVisible();
    await page.mouse.move(10, 10);
    await expect(page.locator('.toast')).toHaveCount(0, { timeout: 9000 });
});

test('a notice takes its colours from tokens, passes axe, and fits a 320 px phone at 200% text', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await openOverview(page);
    await saveInstructions(page, 'Explain with everyday examples.');
    await page.locator('.toast').hover();
    expect((await new AxeBuilder({ page }).include('[data-toasts]').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze()).violations.map((v) => v.id)).toEqual([]);
    await useSentinelTheme(page);
    expect(await foreignColours(page)).toEqual([]);
    await page.setViewportSize({ width: 320, height: 800 });
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    const box = await page.locator('.toast').boundingBox();
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(320);
});
