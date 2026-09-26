import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { foreignColours, openAdminOverview, THEMES, useSentinelTheme, useTheme } from './support.js';

/* The admin audit log (ADR 0003 §10.4): a read-only list, newest first, filtered by action. */

const desktop = { width: 1440, height: 900 };
const phone = { width: 390, height: 844 };

test.use({ reducedMotion: 'reduce' });

async function openAuditLog(page) {
    await openAdminOverview(page);
    await page.goto('/admin/audit-log');
    await page.getByRole('heading', { name: 'Audit log', exact: true }).waitFor();
    await page.waitForLoadState('load');
}

test('an admin sees their own entry into the admin area, and can filter by action', async ({ page }) => {
    await page.setViewportSize(desktop);
    await openAuditLog(page);
    const me = (await page.locator('[data-menu-button] span:not(.avatar)').first().textContent()).trim();

    await expect(page.locator('.app-sidebar').getByRole('link', { name: 'Audit log' })).toHaveAttribute('aria-current', 'page');
    await page.getByLabel('Show').selectOption({ label: 'Entered the admin area' });
    await expect(page.getByRole('listitem').filter({ hasText: me }).filter({ hasText: 'entered the admin area' }).first()).toBeVisible();
    await expect(page.getByRole('listitem').filter({ hasText: 'suspended' })).toHaveCount(0);
});

for (const [name, viewport] of Object.entries({ desktop, phone })) {
    test(`every colour on the audit log comes from a token: ${name}`, async ({ page }) => {
        await page.setViewportSize(viewport);
        await openAuditLog(page);
        await useSentinelTheme(page);
        const idle = await foreignColours(page);
        await page.getByLabel('Show').focus();
        const focused = await foreignColours(page);

        expect(idle, 'idle: colours not from a token').toEqual([]);
        expect(focused, 'filter focus: colours not from a token').toEqual([]);
    });
}

for (const theme of THEMES) {
    test(`axe finds no violations on the audit log: ${theme}`, async ({ page }) => {
        await page.setViewportSize(desktop);
        await openAuditLog(page);
        await useTheme(page, theme);
        const violations = (await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze()).violations;

        expect(violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target).join(' ')}`)).toEqual([]);
    });
}

test('the audit log never scrolls sideways at 320 px, even with 200% text', async ({ page }) => {
    await openAuditLog(page);
    await page.setViewportSize({ width: 320, height: 800 });
    for (const zoom of ['100%', '200%']) {
        await page.evaluate((size) => (document.documentElement.style.fontSize = size), zoom);
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        expect(overflow, `320 px at ${zoom} text`).toBeLessThanOrEqual(0);
    }
});
