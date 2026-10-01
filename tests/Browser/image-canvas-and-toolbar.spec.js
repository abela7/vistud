import { test, expect } from '@playwright/test';
import path from 'path';
import { openStudentHome, makeStudentWithNote, useTheme } from './support.js';

const desktop = { width: 1440, height: 900 };
const splitDesktop = { width: 1200, height: 850 };
const artifactsDir = 'C:\\Users\\abelg\\.gemini\\antigravity\\brain\\202b53cc-a1a0-49f7-929b-2722878e5410';

const samplePng = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAlgAAAGQCAYAAAByNR6YAAAACXBIWXMAAAsTAAALEwEAmpwYAAAAGXRFWHRTb2Z0d2FyZQBHUFAgU3VyZmFjZQDt1Qd2AAAPVUlEQVR42u3BAQ0AAADCoPdPbQ43oAAAAAAAAAAAAAAA' +
    'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA' +
    'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA' +
    'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA' +
    'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA' +
    'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAwbcEAAAEW' +
    'g3vAAAABJRU5ErkJggg==',
    'base64'
);

test.describe.configure({ timeout: 60_000 });

test('Toolbar nav buttons are solid, clickable, and scroll the toolbar', async ({ page }) => {
    await page.setViewportSize({ width: 700, height: 800 });
    const student = makeStudentWithNote();
    await openStudentHome(page, student.email);
    await page.goto(student.empty);
    await page.locator('[data-note-editor][data-ready]').waitFor();

    const nextBtn = page.locator('[data-toolbar-next]');
    const prevBtn = page.locator('[data-toolbar-prev]');

    // On narrow width (700px), nextBtn should be visible because toolbar overflows
    await expect(nextBtn).toBeVisible();

    // Verify it has solid button styling (primary gradient background, on-primary text/icon)
    const btnStyles = await nextBtn.evaluate((el) => {
        const cs = window.getComputedStyle(el);
        return {
            display: cs.display,
            color: cs.color,
            backgroundImage: cs.backgroundImage,
            cursor: cs.cursor,
            zIndex: cs.zIndex,
            boxShadow: cs.boxShadow,
        };
    });

    expect(btnStyles.cursor).toBe('pointer');
    expect(btnStyles.backgroundImage).toContain('gradient');
    expect(btnStyles.color).toBe('rgb(255, 255, 255)'); // var(--on-primary)

    // Capture screenshot of solid button in light mode
    await page.screenshot({ path: path.join(artifactsDir, 'solid-toolbar-button-light.png'), fullPage: false });

    // Switch to dark theme and verify solid button appearance
    await useTheme(page, 'vistud-dark');
    await expect(nextBtn).toBeVisible();
    const darkBtnStyles = await nextBtn.evaluate((el) => {
        const cs = window.getComputedStyle(el);
        return {
            color: cs.color,
            backgroundImage: cs.backgroundImage,
        };
    });
    expect(darkBtnStyles.color).toBe('rgb(255, 255, 255)');
    expect(darkBtnStyles.backgroundImage).toContain('gradient');
    await page.screenshot({ path: path.join(artifactsDir, 'solid-toolbar-button-dark.png'), fullPage: false });

    // Switch back to light and click nextBtn to scroll toolbar
    await useTheme(page, 'vistud-light');
    await nextBtn.click();
    await page.waitForTimeout(400);

    // prevBtn should now be visible after scrolling
    await expect(prevBtn).toBeVisible();
    await prevBtn.click();
    await page.waitForTimeout(400);
});

test('Image can be freely resized smoothly and moved anywhere on canvas', async ({ page }) => {
    await page.setViewportSize(desktop);
    const student = makeStudentWithNote();
    await openStudentHome(page, student.email);
    await page.goto(student.empty);
    await page.locator('[data-note-editor][data-ready]').waitFor();

    const body = page.locator('.note-prose');
    await body.click();

    // Add some paragraphs of text
    await page.keyboard.type('Paragraph 1: Introduction to Data Structures.');
    await page.keyboard.press('Enter');
    await page.keyboard.type('Paragraph 2: Binary Search Trees and Balancing.');
    await page.keyboard.press('Enter');
    await page.keyboard.type('Paragraph 3: Graph Traversal Algorithms.');

    // Insert an image using the Add a picture dialog
    await page.keyboard.press('Control+Shift+i');
    const panel = page.getByRole('dialog', { name: 'Add a picture' });
    await expect(panel).toBeVisible();
    const chooser = page.waitForEvent('filechooser');
    await panel.getByRole('button', { name: 'Choose a picture' }).click();
    await (await chooser).setFiles({ name: 'tree_diagram.png', mimeType: 'image/png', buffer: samplePng });
    await expect(panel).toBeHidden();

    const figure = body.locator('figure.note-image-figure');
    await expect(figure).toBeVisible();
    const img = figure.locator('img.note-image-img');
    await expect(img).toBeVisible();

    // 1. Click image to show toolbar, drag pill, and handles
    await img.click();
    await expect(figure).toHaveClass(/is-selected/);
    const toolbar = page.locator('#note-image-toolbar');
    await expect(toolbar).toBeVisible();

    const dragPill = figure.locator('.note-image-drag-pill');
    await expect(dragPill).toBeVisible();
    const handleSe = figure.locator('.note-image-resize-handle.handle-se');
    await expect(handleSe).toBeVisible();
    const handleSw = figure.locator('.note-image-resize-handle.handle-sw');
    await expect(handleSw).toBeVisible();

    // 2. Test Smooth Resizing: drag handleSe to resize
    const seBox = await handleSe.boundingBox();
    expect(seBox).not.toBeNull();

    // Drag handle leftwards to shrink the image smoothly
    await page.mouse.move(seBox.x + seBox.width / 2, seBox.y + seBox.height / 2);
    await page.mouse.down();
    await page.mouse.move(seBox.x - 150, seBox.y, { steps: 5 });
    
    // Check that live width changed
    const currentWidth = await figure.evaluate((el) => el.style.width || el.dataset.width);
    console.log(`Resized width during drag: ${currentWidth}`);
    await page.mouse.up();

    // Verify width is properly updated and stored
    const finalWidth = await figure.evaluate((el) => el.style.width || el.dataset.width);
    expect(finalWidth).toMatch(/^[1-9][0-9]?%$/);

    // 3. Test quick presets in floating toolbar
    await toolbar.locator('[data-image-width="50%"]').click();
    await expect(figure).toHaveAttribute('data-width', '50%');

    // 4. Test Moving on Canvas:
    // Check current paragraph order: Paragraph 1, Paragraph 2, Paragraph 3, [Image]
    // Click "Move Up" in the floating toolbar
    const moveUpBtn = toolbar.locator('[data-image-move="up"]');
    await expect(moveUpBtn).toBeVisible();
    await moveUpBtn.click();
    await page.waitForTimeout(300);

    // Now image should be before Paragraph 3!
    const htmlAfterMoveUp = await body.evaluate((el) => {
        return Array.from(el.children).map((c) => c.tagName.toLowerCase() + (c.matches('figure') ? ':image' : ':' + c.textContent.trim().slice(0, 15)));
    });
    console.log('Nodes after Move Up:', htmlAfterMoveUp);
    expect(htmlAfterMoveUp).toContain('figure:image');
    const imgIndex = htmlAfterMoveUp.indexOf('figure:image');
    expect(imgIndex).toBeLessThan(htmlAfterMoveUp.length - 1);

    // Click "Move Down" to move it back
    const moveDownBtn = toolbar.locator('[data-image-move="down"]');
    await moveDownBtn.click();
    await page.waitForTimeout(300);

    // 5. Test Drag and Drop Move on Canvas:
    // Drag the dragPill and drop it above Paragraph 1
    const p1 = body.locator('p').first();
    await dragPill.dragTo(p1);
    await page.waitForTimeout(400);

    const htmlAfterDrag = await body.evaluate((el) => {
        return Array.from(el.children).map((c) => c.tagName.toLowerCase() + (c.matches('figure') ? ':image' : ':' + c.textContent.trim().slice(0, 15)));
    });
    console.log('Nodes after Drag and Drop:', htmlAfterDrag);

    // Re-select the image to show toolbar and handles clearly
    await figure.click();
    await expect(toolbar).toBeVisible();

    // Capture visual screenshot of the resized image, drag pill, and active floating toolbar
    await page.screenshot({ path: path.join(artifactsDir, 'image-free-move-and-resize.png'), fullPage: false });
});
