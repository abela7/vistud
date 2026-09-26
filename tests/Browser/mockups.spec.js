import { test } from '@playwright/test';
import { openStudentHome, useTheme } from './support.js';

/*
| Screenshots of the workspace mockups (docs/specs/workspaces.md), written to
| docs/design/mockups/. Run with MOCKUPS=1 against a local server (the
| mockup pages exist only in local development).
*/

test.skip(!process.env.MOCKUPS, 'Set MOCKUPS=1 to regenerate the workspace mockups.');
test.use({ reducedMotion: 'reduce' });

const pages = {
    home: '/_mockups/workspaces',
    overview: '/_mockups/workspaces/biology',
    modules: '/_mockups/workspaces/biology/modules',
    note: '/_mockups/workspaces/biology/notes/mitosis-summary',
    progress: '/_mockups/workspaces/biology/progress',
};

test('workspace mockups', async ({ page }) => {
    await openStudentHome(page);
    for (const [name, path] of Object.entries(pages)) {
        await page.goto(path);
        await page.waitForLoadState('load');
        for (const [size, viewport] of Object.entries({ desktop: { width: 1440, height: 900 }, phone: { width: 390, height: 844 } })) {
            for (const theme of size === 'desktop' ? ['vistud-light', 'vistud-dark'] : ['vistud-light']) {
                await page.setViewportSize(viewport);
                await useTheme(page, theme);
                await page.evaluate(() => document.activeElement?.blur());
                await page.screenshot({ path: `docs/design/mockups/workspace-${name}-${size}-${theme}.png`, fullPage: false });
            }
        }
    }
});
