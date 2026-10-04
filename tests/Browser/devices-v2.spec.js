import { test, expect } from '@playwright/test';
import { makeStudentWithEverything, openStudentHome } from './support.js';

/*
 * ViStud 2 on every device (docs/specs/vistud-2-blueprint.md §3.10, Phase 6): every page that was rebuilt, on a phone (320 and
 * 390 px), a tablet (768 and 1024 px, touch) and a computer. Nothing scrolls sideways (at 320 px also with 200 % text), the
 * phone's five tabs stay on one line, and every dialog is a bottom sheet under 640 px (a panel from the right above it) with
 * its buttons in view and inside the screen.
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

/** A dialog: a sheet at the foot of the screen under 640 px, a panel at its right edge above it. */
async function sheetOrPanel(page, dialog) {
    await page.waitForTimeout(350);
    const box = await dialog.boundingBox();
    const vp = page.viewportSize();
    if (!box) return 'not shown';
    if (vp.width < 640) {
        if (Math.abs(box.x) > 1 || Math.abs(box.width - vp.width) > 2) return `sheet is not the full width (${Math.round(box.x)}, ${Math.round(box.width)} of ${vp.width})`;
        if (Math.abs(box.y + box.height - vp.height) > 2) return `sheet is not at the foot of the screen (bottom ${Math.round(box.y + box.height)} of ${vp.height})`;
        return null;
    }
    if (Math.abs(box.x + box.width - vp.width) > 2) return `panel is not at the right edge (right ${Math.round(box.x + box.width)} of ${vp.width})`;
    return null;
}

for (const [name, device] of Object.entries(devices)) {
    test.describe(name, () => {
        test.use(device);

        test(`every rebuilt page fits a ${name}`, async ({ page }) => {
            test.setTimeout(240_000);
            const student = makeStudentWithEverything();
            const issues = [];
            const note = (where, problem) => problem && issues.push(`${where}: ${problem}`);
            const narrow = device.viewport.width <= 320;
            const w = `/courses/${student.workspace}`;
            await openStudentHome(page, student.email);

            const pages = [
                ['course home', w],
                ['modules', `${w}/modules`],
                ['a module', `${w}/modules/${student.module}`],
                ['a folder', `${w}/folders/${student.folder}`],
                ['cards', `${w}/flashcards`],
                ['questions', `${w}/questions`],
                ['a question', `${w}/questions/${student.question}`],
                ['assignments', `${w}/assignments`],
                ['a new assignment', `${w}/assignments/new`],
                ['progress', `${w}/progress`],
                ['notes and files', `${w}/notes`],
                ['the course calendar', `${w}/calendar`],
                ['the session', `${w}/sessions/${student.session}`],
                ['all calendars', '/calendar'],
                ['settings: AI', '/settings?part=ai'],
                ['settings: appearance', '/settings?part=appearance'],
                ['settings: security', '/settings?part=security'],
                ['settings: your data', '/settings?part=data'],
            ];
            for (const [where, path] of pages) {
                await page.goto(path);
                await page.getByRole('heading', { level: 1 }).first().waitFor();
                await page.waitForLoadState('load');
                note(where, await overflow(page));
                if (narrow) {
                    // 200 % text, the way a student who needs it has it.
                    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
                    note(`${where} at 200% text`, await overflow(page));
                    await page.reload();
                    await page.waitForLoadState('load');
                }
            }

            // The tab bar: five tabs on a phone, each label on one line; Ask and More open sheets.
            await page.goto(w);
            await page.getByRole('heading', { level: 1 }).first().waitFor();
            const phone = device.viewport.width < 768;
            if (phone) {
                const labels = await page.locator('.app-tabbar .tab-item > span').all();
                if (labels.length !== 5) note('tab bar', `${labels.length} tabs, not five`);
                for (const label of labels) {
                    const box = await label.boundingBox();
                    if (box && box.height > 20) note('tab bar', `label "${await label.textContent()}" on two lines`);
                }
                await page.locator('.app-tabbar').getByRole('button', { name: 'More', exact: true }).click();
                note('More sheet', await sheetOrPanel(page, page.locator('#more-sheet')));
                await expect(page.locator('#more-sheet').getByRole('link', { name: 'Assignments' })).toBeVisible();
                await page.keyboard.press('Escape');
            }

            // Ask: the tab bar's on a phone, the top bar's above.
            await page.locator('.app-topbar, .app-tabbar').getByRole('button', { name: 'Ask', exact: true }).click();
            const ask = page.locator('#ask-sheet');
            await expect(ask.getByLabel('Your question', { exact: true })).toBeVisible();
            note('Ask sheet', await sheetOrPanel(page, ask));
            note('Ask sheet', await inside(page, ask.locator('.modal-panel')));
            await page.keyboard.press('Escape');

            // The course dialog: its buttons are in view without scrolling, inside the screen.
            await page.goto(w);
            await page.getByRole('heading', { level: 1 }).first().waitFor();
            await page.getByRole('button', { name: /^More for / }).click();
            await page.getByRole('button', { name: 'Edit course' }).click();
            const form = page.locator('#workspace-form');
            await expect(form.getByLabel('Name')).toBeVisible();
            note('course dialog', await sheetOrPanel(page, form));
            note('course dialog buttons', await inside(page, form.getByRole('button', { name: 'Save changes' })));
            await page.keyboard.press('Escape');

            // The topic sheet from Progress.
            await page.goto(`${w}/progress`);
            await page.getByRole('heading', { level: 1 }).first().waitFor();
            await page.locator('.topic-row').first().getByRole('button').first().click();
            const topic = page.locator('#topic-sheet');
            await expect(topic.getByRole('heading').first()).toBeVisible();
            note('topic sheet', await sheetOrPanel(page, topic));
            note('topic sheet', await overflow(page));
            await page.keyboard.press('Escape');

            // The session's rail is a sheet below 1024 px, a column beside the conversation above it.
            await page.goto(`${w}/sessions/${student.session}`);
            await page.getByRole('heading', { level: 1 }).first().waitFor();
            if (device.viewport.width < 1024) {
                await expect(page.locator('.session-rail')).toBeHidden();
                await page.locator('.topic-switch').click();
                note('session rail sheet', await sheetOrPanel(page, page.locator('dialog[open]').last()));
                await page.keyboard.press('Escape');
            } else {
                await expect(page.locator('.session-rail')).toBeVisible();
            }

            expect(issues, issues.join('\n')).toEqual([]);
        });
    });
}
