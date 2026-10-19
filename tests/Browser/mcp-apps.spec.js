import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/* Panels at a phone width, light and dark (docs/specs/mcp-connections-plan.md M6.6). */

const panels = ['quiz', 'topic', 'session', 'confirm', 'cards'];

for (const panel of panels) {
    for (const scheme of ['light', 'dark']) {
        test(`${panel} at 320px, ${scheme}, has no axe violations`, async ({ page }) => {
            await page.setViewportSize({ width: 320, height: 700 });
            await page.emulateMedia({ colorScheme: scheme });
            await page.goto(`/mcp/panels/${panel}`);
            const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
            expect(results.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target).join(' ')}`)).toEqual([]);
            const box = await page.locator('button').first().boundingBox();
            if (box) {
                expect(box.height).toBeGreaterThanOrEqual(44);
                expect(box.width).toBeGreaterThanOrEqual(44);
            }
        });
    }
}
