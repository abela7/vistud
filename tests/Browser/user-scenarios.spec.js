import { test, expect } from '@playwright/test';
import path from 'path';
import fs from 'fs';
import { openStudentHome, makeStudentWithWorkspaces } from './support.js';

const desktop = { width: 1440, height: 900 };
// Screenshots for a person to look at; test-results/ is never committed.
const artifactsDir = path.join('test-results', 'screens');
// A real course folder on the tester's computer (VISTUD_COURSE_FOLDER=".../Operating Systems").
// Without it these scenarios are skipped: the files are too big to keep in the repository.
const osFolder = process.env.VISTUD_COURSE_FOLDER ?? '';
const lecturePdf = path.join(osFolder, 'Week 1 - Architecture & System Calls', 'Lectures', 'Lecture 01 - Kernel and System Calls.pdf');
const courseDocx = path.join(osFolder, 'Week 1 - Architecture & System Calls', 'Readings', 'Syllabus and Course Policies.docx');
const coursePptx = path.join(osFolder, 'Week 2 - Processes & Scheduling', 'Slides', 'Lecture 02 - Process Management.pptx');

test.describe.configure({ timeout: 90_000 });
test.skip(!osFolder || !fs.existsSync(osFolder), 'Set VISTUD_COURSE_FOLDER to a real course folder to run these.');

test('User testing: 1. Upload real lecture PDF (> 2 MB) and open it', async ({ page }) => {
    await page.setViewportSize(desktop);
    await openStudentHome(page, makeStudentWithWorkspaces([['Operating Systems', 'blue', 'code']]));

    // Open Operating Systems workspace
    await page.locator('main').getByRole('link', { name: 'Operating Systems' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Operating Systems' })).toBeVisible();

    // Go to Modules tab and create a module "Lectures & Readings"
    await page.locator('.app-sidebar').getByRole('link', { name: 'Modules' }).click();
    await page.getByRole('button', { name: 'New module' }).click();
    await page.locator('#structure-dialog').waitFor({ state: 'visible' });
    await page.getByLabel('Title').fill('Week 1: Kernel & Architecture');
    await page.locator('#structure-dialog button:has-text("Add module")').click();
    await page.locator('#structure-dialog').waitFor({ state: 'hidden' });

    // Open module
    await page.getByRole('link', { name: 'Week 1: Kernel & Architecture' }).click();

    // Verify file size is > 2 MB (here 6.8 MB)
    const stat = fs.statSync(lecturePdf);
    console.log(`Testing with real lecture PDF of size: ${(stat.size / (1024 * 1024)).toFixed(2)} MB`);
    expect(stat.size).toBeGreaterThan(2 * 1024 * 1024);

    // Open Upload files dialog
    await page.getByRole('button', { name: 'Upload files' }).click();
    await expect(page.locator('#structure-dialog')).toBeVisible();

    // Set file in input
    await page.locator('input[data-upload-files]').setInputFiles(lecturePdf);

    // Wait for upload to complete and file link to appear in module list
    const fileRow = page.getByRole('link', { name: 'Lecture 01 - Kernel and System Calls' });
    await expect(fileRow).toBeVisible({ timeout: 45_000 });
    await page.screenshot({ path: path.join(artifactsDir, 'test1-pdf-in-module-list.png') });

    // Click file to open preview
    await fileRow.click();
    await page.waitForTimeout(2000);
    await page.screenshot({ path: path.join(artifactsDir, 'modern-file-header-light.png') });
    await page.screenshot({ path: path.join(artifactsDir, 'test1-pdf-over-2mb-opened.png') });

    await page.emulateMedia({ colorScheme: 'dark' });
    await page.evaluate(() => {
        document.documentElement.classList.add('dark');
        document.documentElement.setAttribute('data-theme', 'vistud-dark');
    });
    await page.waitForTimeout(1000);
    await page.screenshot({ path: path.join(artifactsDir, 'modern-file-header-dark.png') });
});

test('User testing: 2. Choose a folder with Operating Systems course structure', async ({ page }) => {
    await page.setViewportSize(desktop);
    await openStudentHome(page, makeStudentWithWorkspaces([['Operating Systems', 'blue', 'code']]));

    await page.locator('main').getByRole('link', { name: 'Operating Systems' }).click();
    await page.locator('.app-sidebar').getByRole('link', { name: 'Modules' }).click();
    await page.getByRole('button', { name: 'New module' }).click();
    await page.getByLabel('Title').fill('Full OS Course');
    await page.locator('#structure-dialog button:has-text("Add module")').click();
    await page.locator('#structure-dialog').waitFor({ state: 'hidden' });

    await page.getByRole('link', { name: 'Full OS Course' }).click();

    // Open upload dialog
    await page.getByRole('button', { name: 'Upload files' }).click();
    await expect(page.locator('#structure-dialog')).toBeVisible();

    // Choose folder via input[data-upload-folder]
    await page.locator('input[data-upload-folder]').setInputFiles(osFolder);

    // Wait for created root folder to appear in module view
    const rootFolderLink = page.getByRole('link', { name: 'Operating Systems' });
    await expect(rootFolderLink).toBeVisible({ timeout: 60_000 });
    await page.screenshot({ path: path.join(artifactsDir, 'test2-root-folder.png') });

    // Open the folder to view subfolders
    await rootFolderLink.click();
    const week1Link = page.getByRole('link', { name: 'Week 1 - Architecture & System Calls' });
    const week2Link = page.getByRole('link', { name: 'Week 2 - Processes & Scheduling' });
    await expect(week1Link).toBeVisible({ timeout: 15_000 });
    await expect(week2Link).toBeVisible({ timeout: 15_000 });
    await page.screenshot({ path: path.join(artifactsDir, 'test2-folder-structure-created.png') });

    // Open Week 1 folder to verify nested subfolders and files
    await week1Link.click();
    await expect(page.getByRole('link', { name: 'Lectures' })).toBeVisible({ timeout: 15_000 });
    await expect(page.getByRole('link', { name: 'Readings' })).toBeVisible({ timeout: 15_000 });
    await page.screenshot({ path: path.join(artifactsDir, 'test2-nested-subfolders.png') });

    // Open Lectures folder to verify the lecture PDF is inside
    await page.getByRole('link', { name: 'Lectures' }).click();
    await expect(page.getByRole('link', { name: 'Lecture 01 - Kernel and System Calls' })).toBeVisible({ timeout: 15_000 });
    await page.screenshot({ path: path.join(artifactsDir, 'test2-files-in-nested-folder.png') });
});

test('User testing: 3. Open a .pptx and a .docx (LibreOffice conversion)', async ({ page }) => {
    await page.setViewportSize(desktop);
    await openStudentHome(page, makeStudentWithWorkspaces([['Operating Systems', 'blue', 'code']]));

    await page.locator('main').getByRole('link', { name: 'Operating Systems' }).click();
    await page.locator('.app-sidebar').getByRole('link', { name: 'Notes & files' }).click();

    // Upload the docx and pptx directly into Notes & files using exact name match on New button
    await page.getByRole('button', { name: 'New', exact: true }).click();
    await page.locator('#new-menu').getByRole('button', { name: 'Upload files' }).click();
    await expect(page.locator('#structure-dialog')).toBeVisible();

    await page.locator('input[data-upload-files]').setInputFiles([courseDocx, coursePptx]);

    // Wait for the files to appear in the list
    const docxLink = page.getByRole('link', { name: 'Syllabus and Course Policies' });
    const pptxLink = page.getByRole('link', { name: 'Lecture 02 - Process Management' });
    await expect(docxLink).toBeVisible({ timeout: 45_000 });
    await expect(pptxLink).toBeVisible({ timeout: 45_000 });
    await page.screenshot({ path: path.join(artifactsDir, 'test3-office-files-uploaded.png') });

    // 3A: Open .docx and verify LibreOffice preview
    const [docxPreviewResponse] = await Promise.all([
        page.waitForResponse(res => res.url().includes('/preview'), { timeout: 60_000 }),
        docxLink.click(),
    ]);
    expect(docxPreviewResponse.status()).toBe(200);
    expect(docxPreviewResponse.headers()['content-type']).toContain('application/pdf');
    await expect(page.locator('.file-preview-office')).toBeVisible({ timeout: 15_000 });
    await expect(page.locator('.file-preview-wait')).toBeHidden({ timeout: 15_000 });
    await expect(page.locator('.file-preview-office iframe:not(.opacity-0)')).toBeVisible({ timeout: 15_000 });
    await page.waitForTimeout(2000);
    await page.screenshot({ path: path.join(artifactsDir, 'test3-docx-preview-libreoffice.png') });

    // Go back to Notes & files and open .pptx
    await page.locator('.app-sidebar').getByRole('link', { name: 'Notes & files' }).click();
    const [pptxPreviewResponse] = await Promise.all([
        page.waitForResponse(res => res.url().includes('/preview'), { timeout: 60_000 }),
        pptxLink.click(),
    ]);
    expect(pptxPreviewResponse.status()).toBe(200);
    expect(pptxPreviewResponse.headers()['content-type']).toContain('application/pdf');
    await expect(page.locator('.file-preview-office')).toBeVisible({ timeout: 15_000 });
    await expect(page.locator('.file-preview-wait')).toBeHidden({ timeout: 15_000 });
    await expect(page.locator('.file-preview-office iframe:not(.opacity-0)')).toBeVisible({ timeout: 15_000 });
    await page.waitForTimeout(2000);
    await page.screenshot({ path: path.join(artifactsDir, 'test3-pptx-preview-libreoffice.png') });
});

test('User testing: 4. Drag a folder onto the upload box', async ({ page }) => {
    await page.setViewportSize(desktop);
    await openStudentHome(page, makeStudentWithWorkspaces([['Operating Systems', 'blue', 'code']]));

    await page.locator('main').getByRole('link', { name: 'Operating Systems' }).click();
    await page.locator('.app-sidebar').getByRole('link', { name: 'Modules' }).click();
    await page.getByRole('button', { name: 'New module' }).click();
    await page.getByLabel('Title').fill('Drag and Drop Test');
    await page.locator('#structure-dialog button:has-text("Add module")').click();
    await page.locator('#structure-dialog').waitFor({ state: 'hidden' });

    await page.getByRole('link', { name: 'Drag and Drop Test' }).click();

    // Open upload dialog
    await page.getByRole('button', { name: 'Upload files' }).click();
    await expect(page.locator('#structure-dialog')).toBeVisible();

    const dropZone = page.locator('.drop-zone');
    await expect(dropZone).toBeVisible();

    // Verify hover/dragover styling
    await dropZone.dispatchEvent('dragover');
    await expect(dropZone).toHaveClass(/is-over/);
    await page.screenshot({ path: path.join(artifactsDir, 'test4-drag-over-hover.png') });

    // Upload Labs folder
    const labsFolder = path.join(osFolder, 'Week 2 - Processes & Scheduling', 'Labs');
    await page.locator('input[data-upload-folder]').setInputFiles(labsFolder);

    // Verify folder appeared
    const labsLink = page.getByRole('link', { name: 'Labs' });
    await expect(labsLink).toBeVisible({ timeout: 30_000 });
    await page.screenshot({ path: path.join(artifactsDir, 'test4-drag-folder-in-module.png') });
});

test('User testing: 5. Full screen document view mode (toggle, resize, escape, light & dark mode)', async ({ page }) => {
    await page.setViewportSize(desktop);
    await openStudentHome(page, makeStudentWithWorkspaces([['Operating Systems', 'blue', 'code']]));

    await page.locator('main').getByRole('link', { name: 'Operating Systems' }).click();
    await page.locator('.app-sidebar').getByRole('link', { name: 'Modules' }).click();
    await page.getByRole('button', { name: 'New module' }).click();
    await page.getByLabel('Title').fill('Fullscreen Module');
    await page.locator('#structure-dialog button:has-text("Add module")').click();
    await page.locator('#structure-dialog').waitFor({ state: 'hidden' });

    await page.getByRole('link', { name: 'Fullscreen Module' }).click();

    await page.getByRole('button', { name: 'Upload files' }).click();
    await expect(page.locator('#structure-dialog')).toBeVisible();
    await page.locator('input[data-upload-files]').setInputFiles(lecturePdf);

    const fileRow = page.getByRole('link', { name: 'Lecture 01 - Kernel and System Calls' });
    await expect(fileRow).toBeVisible({ timeout: 45_000 });
    await fileRow.click();

    // Verify file preview page loaded
    const filePage = page.locator('.file-page');
    await expect(filePage).toBeVisible();

    const fsBtn = page.locator('[data-fullscreen-button]');
    await expect(fsBtn).toBeVisible();
    await expect(fsBtn).toContainText('Full screen');
    await expect(filePage).not.toHaveClass(/is-fullscreen/);
    await page.screenshot({ path: path.join(artifactsDir, 'fullscreen-toolbar-normal-light.png') });

    // Click to enter full screen
    await fsBtn.click();
    await expect(filePage).toHaveClass(/is-fullscreen/);
    await expect(fsBtn).toContainText('Exit full screen');
    await expect(fsBtn).toContainText('Esc');

    // Verify preview box expanded to full viewport height
    const previewBox = await page.locator('.file-preview').boundingBox();
    console.log(`Fullscreen preview box height: ${previewBox?.height}px on ${desktop.height}px viewport`);
    expect(previewBox?.height).toBeGreaterThan(desktop.height - 80);

    await page.waitForTimeout(1000);
    await page.screenshot({ path: path.join(artifactsDir, 'fullscreen-document-light.png') });

    // Emulate dark mode
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.evaluate(() => {
        document.documentElement.classList.add('dark');
        document.documentElement.setAttribute('data-theme', 'vistud-dark');
    });
    await page.waitForTimeout(1000);
    await page.screenshot({ path: path.join(artifactsDir, 'fullscreen-document-dark.png') });

    // Press Escape to exit full screen
    await page.keyboard.press('Escape');
    await expect(filePage).not.toHaveClass(/is-fullscreen/);
    await expect(fsBtn).toContainText('Full screen');

    // Re-enter full screen and exit via button
    await fsBtn.click();
    await expect(filePage).toHaveClass(/is-fullscreen/);
    await fsBtn.click();
    await expect(filePage).not.toHaveClass(/is-fullscreen/);
});

test('User testing: 6. Split screen document & resizable note pane (standard & full screen)', async ({ page }) => {
    await page.setViewportSize(desktop);
    await openStudentHome(page, makeStudentWithWorkspaces([['Operating Systems', 'blue', 'code']]));

    await page.locator('main').getByRole('link', { name: 'Operating Systems' }).click();
    await page.locator('.app-sidebar').getByRole('link', { name: 'Modules' }).click();
    await page.getByRole('button', { name: 'New module' }).click();
    await page.getByLabel('Title').fill('Split Screen Module');
    await page.locator('#structure-dialog button:has-text("Add module")').click();
    await page.locator('#structure-dialog').waitFor({ state: 'hidden' });

    await page.getByRole('link', { name: 'Split Screen Module' }).click();

    await page.getByRole('button', { name: 'Upload files' }).click();
    await expect(page.locator('#structure-dialog')).toBeVisible();
    await page.locator('input[data-upload-files]').setInputFiles(lecturePdf);

    const fileRow = page.getByRole('link', { name: 'Lecture 01 - Kernel and System Calls' });
    await expect(fileRow).toBeVisible({ timeout: 45_000 });
    await fileRow.click();

    // Verify on file page
    const filePage = page.locator('.file-page');
    await expect(filePage).toBeVisible();

    const noteToggle = page.locator('[data-split-toggle]');
    await expect(noteToggle).toBeVisible();
    await expect(noteToggle).toContainText('Take notes');

    // 1. Click "Take notes" on desktop -> opens split screen
    await noteToggle.click();
    await expect(page.locator('.file-pane-note')).toBeVisible();
    await expect(page.locator('.file-split-handle')).toBeVisible();
    await expect(noteToggle).toContainText('Close notes');

    // Verify note iframe loaded
    const noteIframe = page.locator('.file-pane-note iframe');
    await expect(noteIframe).toBeVisible();

    await page.waitForTimeout(1500);
    const frame = page.frameLocator('.file-pane-note iframe');
    await frame.locator('[data-note-editor][data-ready]').waitFor({ state: 'attached' });
    await frame.locator('.note-prose').click();
    await page.keyboard.type('HEy are');
    await page.waitForTimeout(2500);
    await page.screenshot({ path: path.join(artifactsDir, 'split-screen-clean-autosave.png') });
    await page.screenshot({ path: path.join(artifactsDir, 'split-screen-standard-light.png') });

    // 2. Test splitter dragging to resize
    const handle = page.locator('.file-split-handle');
    const handleBox = await handle.boundingBox();
    expect(handleBox).not.toBeNull();

    // Drag handle 150px to the left (make note pane larger)
    await page.mouse.move(handleBox.x + handleBox.width / 2, handleBox.y + handleBox.height / 2);
    await page.mouse.down();
    await page.mouse.move(handleBox.x - 150, handleBox.y + handleBox.height / 2, { steps: 5 });
    await page.mouse.up();

    // Verify doc width changed
    const docPane = page.locator('.file-pane-doc');
    const docBoxAfterDrag = await docPane.boundingBox();
    console.log(`Doc pane width after dragging left: ${docBoxAfterDrag?.width}px`);
    expect(docBoxAfterDrag?.width).toBeLessThan(handleBox.x);

    await page.screenshot({ path: path.join(artifactsDir, 'split-screen-resized.png') });

    // 3. Test double-click to reset split (50/50)
    console.log('Step 3: resetting split');
    await page.locator('.file-split-handle').dblclick({ force: true });
    await page.waitForTimeout(500);

    // 4. Toggle Full Screen mode while split view is active
    console.log('Step 4: entering fullscreen with split view');
    const fsBtn = page.locator('[data-fullscreen-button]');
    await fsBtn.click();
    await expect(filePage).toHaveClass(/is-fullscreen/);
    await expect(page.locator('.file-pane-note')).toBeVisible();
    await expect(page.locator('.file-pane-doc')).toBeVisible();

    await page.waitForTimeout(1000);
    await page.screenshot({ path: path.join(artifactsDir, 'split-screen-fullscreen-light.png') });

    // Dark mode in full screen split view
    console.log('Step 4: capturing fullscreen dark mode');
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.evaluate(() => {
        document.documentElement.classList.add('dark');
        document.documentElement.setAttribute('data-theme', 'vistud-dark');
    });
    await page.waitForTimeout(1000);
    await page.screenshot({ path: path.join(artifactsDir, 'split-screen-fullscreen-dark.png') });

    // 5. Exit full screen (via Esc) -> split screen remains active
    console.log('Step 5: exiting fullscreen');
    await page.keyboard.press('Escape');
    await expect(filePage).not.toHaveClass(/is-fullscreen/);
    await expect(page.locator('.file-pane-note')).toBeVisible();

    // 6. Close notes via note pane close button
    console.log('Step 6: closing note pane');
    const closeNoteBtn = page.locator('.note-pane-header button[title="Close note pane"]');
    await closeNoteBtn.click();
    await expect(page.locator('.file-pane-note')).toBeHidden();
    await expect(noteToggle).toContainText('Take notes');
    console.log('Step 6: completed successfully');
});

