import { test, expect } from '@playwright/test';
import { makeStudentWithStudyFiles, openStudentHome } from './support.js';

/*
 * Everything added on 2026-09-30 and 2026-10-01, on a phone (320 and 390 px), a tablet (768 and 1024 px,
 * touch) and a computer: nothing scrolls sideways, every menu and panel is inside the screen, a PDF and a
 * Word preview show where the browser can't show a PDF itself (PDF.js), a finger resizes a picture,
 * folders are offered only where a folder can be chosen, and the tab bar's labels stay on one line.
 */

const devices = {
    'phone-320': { viewport: { width: 320, height: 640 }, hasTouch: true, isMobile: true },
    'phone-390': { viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true },
    'tablet-768': { viewport: { width: 768, height: 1024 }, hasTouch: true, isMobile: true },
    'tablet-1024': { viewport: { width: 1024, height: 768 }, hasTouch: true, isMobile: true },
    'computer-1440': { viewport: { width: 1440, height: 900 } },
};

test.use({ reducedMotion: 'reduce' });

async function overflow(page) {
    return page.evaluate(() => {
        const vw = document.documentElement.clientWidth;
        const sw = document.documentElement.scrollWidth;
        if (sw <= vw + 1) return null;
        const out = [...document.querySelectorAll('body *')].filter((el) => {
            const r = el.getBoundingClientRect();
            return r.width > 0 && r.right > vw + 1;
        }).slice(0, 6).map((el) => `${el.tagName.toLowerCase()}.${[...el.classList].slice(0, 3).join('.')}(${Math.round(el.getBoundingClientRect().right)})`);
        return `sideways scroll ${sw}>${vw}: ${out.join(' | ')}`;
    });
}
async function inside(page, locator) {
    const box = await locator.boundingBox();
    const vp = page.viewportSize();
    if (!box) return 'not shown';
    if (box.x < -1 || box.y < -1 || box.x + box.width > vp.width + 1 || box.y + box.height > vp.height + 1) return `off screen (${Math.round(box.x)},${Math.round(box.y)} ${Math.round(box.width)}x${Math.round(box.height)})`;
    return null;
}

for (const [name, device] of Object.entries(devices)) {
    test.describe(name, () => {
        test.use(device);
        test(`everything new works on a ${name}`, async ({ page, context }) => {
            test.setTimeout(180_000);
            const urls = makeStudentWithStudyFiles();
            const issues = [];
            const note = (where, problem) => problem && issues.push(`${where}: ${problem}`);
            await openStudentHome(page, urls.email);

            // 1. My workspaces: cards, card menu, delete dialog.
            await page.goto('/');
            await page.locator('main').getByRole('link', { name: 'Operating Systems' }).waitFor();
            note('home', await overflow(page));
            await page.getByRole('button', { name: 'Actions for Operating Systems' }).click();
            const menu = page.locator('.row-menu:popover-open');
            await menu.waitFor();
            note('home card menu', await inside(page, menu));
            await menu.getByRole('button', { name: 'Delete workspace' }).click();
            const del = page.locator('#workspace-delete-dialog');
            await expect(del).toBeVisible();
            await page.waitForTimeout(600);
            note('delete dialog', await inside(page, del.locator('.modal-panel')));
            await del.getByRole('button', { name: 'Cancel' }).click();

            // 2. Upload dialog in a folder.
            await page.goto(urls.folder);
            await page.getByRole('heading', { level: 1, name: 'Lectures' }).waitFor();
            note('folder', await overflow(page));
            await page.locator('main').getByRole('button', { name: 'New', exact: true }).click();
            await page.locator('#new-menu').getByRole('button', { name: 'Upload files' }).click();
            await page.locator('#structure-dialog').getByRole('heading', { name: /Upload files/ }).waitFor();
            note('upload dialog', await overflow(page));
            // A phone or a tablet can't choose a folder: the button is only where one can.
            const folderButton = page.locator('#structure-dialog').getByRole('button', { name: 'Choose a folder' });
            if ((await folderButton.isVisible()) === Boolean(device.hasTouch)) note('upload dialog', `Choose a folder ${device.hasTouch ? 'offered on a touch screen' : 'missing on a computer'}`);
            await page.keyboard.press('Escape');

            // 3. PDF page, Take notes.
            await page.goto(urls.pdf);
            const h1 = page.getByRole('heading', { level: 1 });
            await h1.waitFor();
            note('pdf page', await overflow(page));
            const usesPdfJs = await page.evaluate(() => navigator.pdfViewerEnabled === false || matchMedia('(hover: none) and (pointer: coarse)').matches);
            if (usesPdfJs) {
                const drawn = await page.locator('.pdf-view .pdf-page canvas').first().waitFor({ timeout: 20000 }).then(() => true, () => false);
                if (!drawn) note('pdf page', 'PDF.js drew nothing');
            } else if (device.hasTouch) note('pdf page', 'a touch screen was given the browser\'s own PDF viewer');
            const tabs = page.locator('.app-tabbar .tab-item > span');
            for (const label of await tabs.all()) {
                const box = await label.boundingBox();
                if (box && box.height > 20) note('tab bar', `label "${await label.textContent()}" on two lines`);
            }
            const h1box = await h1.boundingBox();
            if (!h1box || h1box.width < 60) note('pdf page', `file name squeezed (${h1box ? Math.round(h1box.width) : 0}px)`);
            const take = page.getByRole('link', { name: 'Take notes' });
            if (page.viewportSize().width >= 1024) {
                await take.click();
                const pane = page.frameLocator('[data-note-pane] iframe');
                await pane.locator('[data-note-editor][data-ready]').waitFor();
                note('split pane', await overflow(page));
                note('split pane note', await pane.locator('html').evaluate(() => (document.documentElement.scrollWidth > document.documentElement.clientWidth + 1 ? `pane scrolls sideways ${document.documentElement.scrollWidth}>${document.documentElement.clientWidth}` : null)));
            } else {
                const popup = context.waitForEvent('page');
                await take.click();
                const tab = await popup;
                await tab.locator('[data-note-editor][data-ready]').waitFor();
                if (!new URL(tab.url()).searchParams.has('window')) note('take notes', `opened ${tab.url()}`);
                note('take-notes tab', await overflow(tab));
                await tab.close();
            }

            // 4. Word page.
            await page.goto(urls.docx);
            await page.getByRole('heading', { level: 1 }).waitFor();
            note('docx page', await overflow(page));
            // Word is shown as a PDF made by LibreOffice, where it is installed.
            if (usesPdfJs && (await page.locator('.file-preview-office').count()) > 0) {
                const drawn = await page.locator('.pdf-view .pdf-page canvas').first().waitFor({ timeout: 60000 }).then(() => true, () => false);
                if (!drawn) note('docx page', 'Word preview not drawn');
            }

            // 5. Note page: share menu, picture toolbar.
            await page.goto(urls.note);
            await page.locator('[data-note-editor][data-ready]').waitFor();
            note('note page', await overflow(page));
            const share = page.locator('.note-header-bar').getByRole('button', { name: 'Share' });
            if (await share.isVisible()) {
                await share.click();
                const shareMenu = page.locator('#note-share-menu');
                await shareMenu.waitFor();
                note('share menu', await inside(page, shareMenu));
                await page.keyboard.press('Escape');
            } else note('note page', 'Share button not visible in the header');
            const figure = page.locator('figure.note-image-figure').first();
            await figure.scrollIntoViewIfNeeded();
            await figure.locator('img').click();
            const imageBar = page.locator('#note-image-toolbar');
            if (await imageBar.isVisible().catch(() => false)) note('picture toolbar', await inside(page, imageBar));
            else note('picture toolbar', 'did not appear after tapping the picture');
            const handle = figure.locator('.note-image-resize-handle').first();
            const hb = await handle.boundingBox().catch(() => null);
            if (!hb) note('picture', 'no corner to resize it');
            if (hb && device.hasTouch) {
                // A finger dragging the corner (touch events, as a phone sends them).
                const before = (await figure.boundingBox()).width;
                const cdp = await context.newCDPSession(page);
                const [x, y] = [hb.x + hb.width / 2, hb.y + hb.height / 2];
                await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x, y }] });
                for (let step = 1; step <= 5; step++) await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: x - step * 8, y }] });
                await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
                const after = (await figure.boundingBox()).width;
                if (Math.abs(after - before) < 10) note('picture', 'a finger dragging the corner did not resize it');
            }

            // 6. Full screen: the small bar holds Share.
            await page.keyboard.press('Escape');
            const focus = page.locator('[data-note-focus]').first();
            if (await focus.isVisible()) {
                await focus.click();
                const bar = page.locator('.note-bar-tools');
                await bar.waitFor();
                note('full screen', await overflow(page));
                note('full screen bar', await inside(page, bar));
                const small = bar.getByRole('button', { name: 'Share' });
                if (await small.isVisible()) {
                    await small.click();
                    note('full screen share menu', await inside(page, page.locator('#note-share-menu-small')));
                    await page.keyboard.press('Escape');
                } else note('full screen', 'Share not visible in the bar');
            } else note('note page', 'Full screen button not visible');

            // 7. The note in a window of its own.
            await page.goto(urls.window);
            await page.locator('[data-note-editor][data-ready]').waitFor();
            note('window', await overflow(page));
            note('window bar', await inside(page, page.locator('.note-bar-tools')));

            expect(issues).toEqual([]);
        });
    });
}
