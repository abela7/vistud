import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
    foreignColours,
    makeStudentWithModules,
    openStudentHome,
    THEMES,
    useSentinelTheme,
    useTheme,
} from './support.js';

/* Bulk actions: selection mode, action bar, range selection, mobile placement, axe, and theme tokens (DESIGN.md §5). */

const desktop = { width: 1440, height: 900 };
const phone = { width: 390, height: 844 };
const appRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..');

function makeStudentWithWorkspaceNotes() {
    const email = makeStudentWithModules();
    const php = process.env.PHP_BINARY || 'php';
    const code = [
        `$p = app(\\App\\Identity\\PrincipalFactory::class)->forUser(\\App\\Models\\User::query()->where('email', '${email}')->firstOrFail(), 'web');`,
        `$w = app(\\App\\Study\\Workspaces::class)->list($p)[0];`,
        `$notes = app(\\App\\Study\\Notes::class);`,
        `$notes->create($p, 'workspace', $w->id, 'Syllabus note');`,
        `$notes->create($p, 'workspace', $w->id, 'Reading list note');`,
        `$notes->create($p, 'workspace', $w->id, 'Lab safety note');`,
    ].join(' ');
    execFileSync(php, ['artisan', 'tinker', '--execute', code], { cwd: appRoot, stdio: 'pipe' });

    return { email };
}

const analyse = async (page, only = null) => {
    const builder = new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']);
    return (await (only ? builder.include(only) : builder).analyze()).violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target).join(' ')}`);
};

test.use({ reducedMotion: 'reduce' });
test.describe.configure({ timeout: 60_000 });

async function openNotesAndFiles(page, email) {
    await openStudentHome(page, email);
    await page.locator('main').getByRole('link', { name: 'Biology' }).click();
    await page.getByRole('heading', { level: 1, name: 'Biology' }).waitFor();
    // Notes & files is reached from Modules ("All notes & files"); its page is the course's /notes.
    await page.goto(`${page.url().replace(/\/$/, '')}/notes`);
    await page.getByRole('heading', { level: 1, name: 'Notes & files' }).waitFor();
    await page.waitForLoadState('load');
}

test('student toggles selection mode, selects items, uses keyboard shortcuts, and clears selection', async ({ page }) => {
    await page.setViewportSize(desktop);
    const { email } = makeStudentWithWorkspaceNotes();
    await openNotesAndFiles(page, email);

    // Initial state: not in select mode
    const selectBtn = page.getByRole('button', { name: 'Select' });
    await expect(selectBtn).toBeVisible();

    const selectionBar = page.locator('.selection-bar-wrap');
    await expect(selectionBar).toBeHidden();

    // Toggle select mode
    await selectBtn.click();
    await expect(page.locator('.is-selecting')).toBeVisible();
    await expect(selectionBar).toBeVisible();
    await expect(selectionBar.getByRole('button', { name: 'Done' })).toBeVisible();

    // Check count says 0 selected
    await expect(page.locator('.selection-count')).toContainText('0 selected');

    // Click on a note row to select it
    const noteRow = page.locator('main [data-select-key^="note:"].item-row').first();
    await noteRow.click();
    await expect(page.locator('.selection-count')).toContainText('1 selected');
    await expect(noteRow).toHaveClass(/is-selected/);

    // Click "Select all" link in the toolbar
    const selectAllBtn = selectionBar.getByRole('button', { name: 'Select all' });
    await selectAllBtn.click();
    const rowCount = await page.locator('main [data-select-key].item-row').count();
    await expect(page.locator('.selection-count')).toContainText(`${rowCount} selected`);

    // Click "Clear" link
    const clearBtn = selectionBar.getByRole('button', { name: 'Clear' });
    await clearBtn.click();
    await expect(page.locator('.selection-count')).toContainText('0 selected');

    // Select all via Ctrl+A keyboard shortcut
    await page.keyboard.press('Control+a');
    await expect(page.locator('.selection-count')).toContainText(`${rowCount} selected`);

    // Exit selection mode with Escape key
    await page.keyboard.press('Escape');
    await expect(selectionBar).toBeHidden();
    await expect(page.locator('.is-selecting')).toHaveCount(0);
});

test('shift-click performs range selection across multiple items', async ({ page }) => {
    await page.setViewportSize(desktop);
    const { email } = makeStudentWithWorkspaceNotes();
    await openNotesAndFiles(page, email);

    // Enter select mode
    await page.getByRole('button', { name: 'Select' }).click();
    const rows = page.locator('main [data-select-key].item-row');
    const count = await rows.count();

    expect(count).toBeGreaterThanOrEqual(2);

    // Click first item
    await rows.nth(0).click();
    await expect(page.locator('.selection-count')).toContainText('1 selected');

    // Shift-click second item
    await rows.nth(1).click({ modifiers: ['Shift'] });
    await expect(page.locator('.selection-count')).toContainText('2 selected');
});

test('bulk trash displays toast with undo that restores items', async ({ page }) => {
    await page.setViewportSize(desktop);
    const { email } = makeStudentWithWorkspaceNotes();
    await openNotesAndFiles(page, email);

    // Enter select mode
    await page.getByRole('button', { name: 'Select' }).click();

    // Select a note
    const noteRow = page.locator('main [data-select-key^="note:"].item-row').first();
    await noteRow.click();

    // Click "Move to trash" button in the selection toolbar
    const trashBtn = page.locator('.selection-bar').getByRole('button', { name: 'Move to trash' });
    await trashBtn.click();

    // Expect toast notification with Undo button
    const toast = page.locator('.toast');
    await expect(toast).toBeVisible();
    await expect(toast).toContainText('moved to the trash');

    const undoBtn = toast.locator('[data-toast-action]');
    await expect(undoBtn).toBeVisible();
    await expect(undoBtn).toHaveText('Undo');

    // Click Undo
    await undoBtn.click();
    await expect(page.locator('.toast')).toContainText('restored');
});

test('mobile selection bar is positioned above bottom navigation with touch targets >= 44px', async ({ page }) => {
    await page.setViewportSize(phone);
    const { email } = makeStudentWithWorkspaceNotes();
    await openNotesAndFiles(page, email);

    // Enter select mode
    await page.getByRole('button', { name: 'Select' }).click();

    const selectionBarWrap = page.locator('.selection-bar-wrap');
    await expect(selectionBarWrap).toBeVisible();

    // Evaluate computed bottom position or placement
    const position = await selectionBarWrap.evaluate((el) => {
        const style = getComputedStyle(el);
        return {
            position: style.position,
            bottom: style.bottom,
        };
    });

    expect(position.position).toBe('fixed');

    // Check touch target of the selection checkboxes (minimum 44px = 2.75rem)
    const firstBox = page.locator('.selection-check .checkbox-box').first();
    await expect(firstBox).toBeVisible();
    const box = await firstBox.boundingBox();
    expect(box).not.toBeNull();
    expect(box.height).toBeGreaterThanOrEqual(43.5);
    expect(box.width).toBeGreaterThanOrEqual(43.5);
});

test('selection bar and selected state pass accessibility checks across all themes without foreign colours', async ({ page }) => {
    await page.setViewportSize(desktop);
    const { email } = makeStudentWithWorkspaceNotes();
    await openNotesAndFiles(page, email);

    // Enter selection mode
    await page.getByRole('button', { name: 'Select' }).click();

    // Select the first note
    await page.locator('.item-row').first().click();

    // Check accessibility on themes
    for (const theme of THEMES) {
        await useTheme(page, theme);
        const violations = await analyse(page, '.selection-bar-wrap');
        expect(violations).toEqual([]);
    }

    // Check sentinel theme: no foreign colours in selection bar or selected item
    await useSentinelTheme(page);
    const foreign = await foreignColours(page);
    expect(foreign).toEqual([]);
});

test('selecting a module by clicking its checkbox enables bulk delete and opens confirmation dialog', async ({ page }) => {
    await page.setViewportSize(desktop);
    const { email } = makeStudentWithWorkspaceNotes();
    await openStudentHome(page, email);
    await page.locator('main').getByRole('link', { name: 'Biology' }).click();
    await page.getByRole('heading', { level: 1, name: 'Biology' }).waitFor();
    const nav = page.viewportSize().width < 768 ? '.app-tabbar' : '.app-sidebar';
    await page.locator(nav).getByRole('link', { name: 'Modules' }).click();
    await page.getByRole('heading', { level: 1, name: 'Modules' }).waitFor();
    await page.waitForLoadState('load');

    // Enter selection mode
    await page.getByRole('button', { name: 'Select' }).click();

    // The selection bar appears with Delete disabled initially
    const selectionBar = page.locator('.selection-bar');
    await expect(selectionBar).toBeVisible();
    const deleteBtn = selectionBar.getByRole('button', { name: 'Delete' });
    await expect(deleteBtn).toBeDisabled();

    // Click directly on the checkbox of the second module
    const secondModule = page.locator('.module-tile').nth(1);
    const secondModuleCheck = secondModule.locator('.selection-checkbox');
    await secondModuleCheck.click();

    // Selection count is now 1, the tile has .is-selected, and Delete button is enabled!
    await expect(page.locator('.selection-count')).toContainText('1 selected');
    await expect(secondModule).toHaveClass(/is-selected/);
    await expect(deleteBtn).toBeEnabled();

    // Click Delete button
    await deleteBtn.click();

    // Dialog opens confirming deletion of 1 item
    const dialog = page.locator('#structure-dialog');
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole('heading', { name: 'Delete 1 item?' })).toBeVisible();
    await expect(dialog).toContainText('Only empty folders and modules can be deleted.');

    // Confirm deletion
    await dialog.getByRole('button', { name: 'Delete' }).click();
    await expect(dialog).toBeHidden();
    await expect(page.locator('.toast')).toContainText('1 item is deleted');
});
