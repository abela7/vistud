import zlib from 'node:zlib';
import { test, expect } from '@playwright/test';
import { makeStudentWithEverything, openStudentHome } from './support.js';

/*
| Every menu opens whole (the owner's review, 2026-09-29: the workspace switcher's menu was cut off by the
| collapsed sidebar). On every page, at every size and with the sidebar open or collapsed, each menu button is
| pressed and its menu must be inside the window, with every part of it its own: not clipped by the sidebar, a
| list or a panel, and not covered by anything.
*/

test.use({ reducedMotion: 'reduce' });
test.describe.configure({ timeout: 180_000 });

const label = (button) => button.evaluate((el) => (el.getAttribute('aria-label') || el.getAttribute('title') || el.textContent).replace(/\s+/g, ' ').trim().slice(0, 50));

/** Opens the menu of `button` and reports what is wrong with it, if anything. */
async function inspect(page, button, where) {
    const name = await label(button);
    await button.click();
    // A menu button names its menu by aria-controls; the editor's toolbar buttons by data-menu-for.
    const panel = page.locator(`#${(await button.getAttribute('aria-controls')) ?? (await button.getAttribute('data-menu-for'))}`);
    await expect(panel, `${where}: “${name}” opens`).toBeVisible();
    const found = await panel.evaluate((el) => {
        const r = el.getBoundingClientRect();
        const problems = [];
        if (r.left < -0.5 || r.top < -0.5 || r.right > innerWidth + 0.5 || r.bottom > innerHeight + 0.5) {
            problems.push(`outside the window (${Math.round(r.left)},${Math.round(r.top)} to ${Math.round(r.right)},${Math.round(r.bottom)} in ${innerWidth}x${innerHeight})`);
        }
        // Points in from every edge, and the middle: each must belong to the menu, so nothing clips or covers it.
        const inset = 5;
        const xs = [r.left + inset, r.left + r.width / 2, r.right - inset];
        const ys = [r.top + inset, r.top + r.height / 2, r.bottom - inset];
        const lost = [];
        for (const x of xs) {
            for (const y of ys) {
                if (x < 0 || y < 0 || x > innerWidth || y > innerHeight) continue;
                const top = document.elementFromPoint(x, y);
                if (!top || !el.contains(top)) lost.push(`${Math.round(x)},${Math.round(y)} is ${top?.className?.toString().slice(0, 30) || top?.tagName}`);
            }
        }
        if (lost.length) problems.push(`covered or clipped at ${lost.slice(0, 3).join('; ')}`);
        if (el.scrollWidth > el.clientWidth + 1) problems.push('its words are cut off sideways');
        return problems;
    });
    await page.keyboard.press('Escape');
    await expect(panel).toBeHidden();
    return found.map((problem) => `${where}: “${name}” ${problem}`);
}

/** Every menu button in view on this page, each opened where it is, and again at the foot of the window. */
async function everyMenu(page, where, { atTheFoot = false } = {}) {
    const problems = [];
    const buttons = '[data-menu-button]:visible, [data-menu-for]:visible';
    const count = await page.locator(buttons).count();
    for (let i = 0; i < count; i++) {
        const button = page.locator(buttons).nth(i);
        await button.scrollIntoViewIfNeeded();
        problems.push(...await inspect(page, button, where));
        if (atTheFoot) {
            await button.evaluate((el) => el.scrollIntoView({ block: 'end' }));
            problems.push(...await inspect(page, button, `${where} (at the foot of the window)`));
        }
    }
    return { problems, count };
}

function pages(s) {
    const w = `/workspaces/${s.workspace}`;
    return [
        ['Home', '/'],
        ['Overview', w],
        ['Modules', `${w}/modules`],
        ['A module', `${w}/modules/${s.module}`],
        ['A folder', `${w}/folders/${s.folder}`],
        ['Notes & files', `${w}/notes`],
        ['Questions', `${w}/modules/${s.module}/questions`],
        ['Progress', `${w}/progress`],
        ['Flashcards', `${w}/flashcards`],
        ['A note', `${w}/notes/${s.note}`],
        ['A file', `${w}/files/${s.file}`],
        ['A session', `${w}/sessions/${s.session}`],
    ];
}

const sizes = {
    'a laptop, sidebar open': { width: 1440, height: 900 },
    'a laptop, sidebar collapsed': { width: 1440, height: 900, collapsed: true },
    'a small laptop, sidebar collapsed': { width: 1280, height: 720, collapsed: true },
    'a tablet': { width: 1024, height: 768 },
    'a phone': { width: 390, height: 844 },
    'a small phone': { width: 320, height: 568 },
};

for (const [name, { collapsed, ...viewport }] of Object.entries(sizes)) {
    test(`every menu opens whole on every page: ${name}`, async ({ page }) => {
        await page.setViewportSize(viewport);
        const student = makeStudentWithEverything();
        await openStudentHome(page, student.email);
        if (collapsed) await page.evaluate(() => localStorage.setItem('vistud.sidebar', 'collapsed'));
        const problems = [];
        const counts = {};
        for (const [title, path] of pages(student)) {
            await page.goto(path);
            await page.waitForLoadState('load');
            const { problems: found, count } = await everyMenu(page, `${title}`, { atTheFoot: ['Modules', 'A module', 'Questions', 'Progress'].includes(title) });
            problems.push(...found);
            counts[title] = count;
        }
        // The pages do hold menus: a run that found none would prove nothing.
        expect(Object.values(counts).reduce((sum, n) => sum + n, 0)).toBeGreaterThan(25);
        expect(problems).toEqual([]);
    });
}

test('the workspace switcher opens whole inside the slide-in menu', async ({ page }) => {
    await page.setViewportSize({ width: 1024, height: 768 });
    const student = makeStudentWithEverything();
    await openStudentHome(page, student.email);
    await page.goto(`/workspaces/${student.workspace}/modules`);
    await page.getByRole('button', { name: 'Open menu' }).click();
    const drawer = page.locator('#app-drawer');
    await expect(drawer).toBeVisible();
    const switcher = drawer.locator('[data-menu-button]');
    expect(await inspect(page, switcher, 'The slide-in menu')).toEqual([]);
});

test('the pinned notes\' list opens whole, above the tab bar and inside the window', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    const student = makeStudentWithEverything();
    await openStudentHome(page, student.email);
    const w = `/workspaces/${student.workspace}`;
    for (const id of [student.note, student.topNote]) {
        await page.goto(`${w}/notes/${id}`);
        await page.locator('[data-note-editor][data-ready]').waitFor();
        await page.getByRole('button', { name: 'Pin', exact: true }).click();
        await expect(page.getByRole('button', { name: 'Unpin', exact: true })).toBeVisible();
    }
    await page.goto(`${w}/modules`);
    const { problems, count } = await everyMenu(page, 'Modules with two pins');
    expect(count).toBeGreaterThan(1);
    expect(problems).toEqual([]);
});

/** A picture, drawn as a plain block of colour: real-sized, so it can be clicked, and its bar is a real bar's size. */
function picture(width, height) {
    const rows = Buffer.alloc((width * 4 + 1) * height);
    for (let y = 0; y < height; y++) {
        for (let x = 0; x < width; x++) rows.set([90, 160, 200, 255], y * (width * 4 + 1) + 1 + x * 4);
    }
    const chunk = (type, data) => {
        const size = Buffer.alloc(4);
        size.writeUInt32BE(data.length);
        const body = Buffer.concat([Buffer.from(type), data]);
        const check = Buffer.alloc(4);
        check.writeUInt32BE(zlib.crc32(body));
        return Buffer.concat([size, body, check]);
    };
    const head = Buffer.alloc(13);
    head.writeUInt32BE(width, 0);
    head.writeUInt32BE(height, 4);
    head.set([8, 6], 8);
    return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', head), chunk('IDAT', zlib.deflateSync(rows)), chunk('IEND', Buffer.alloc(0))]);
}

/** The bar over a selected picture: inside the window, and on top (not under the top bar), at any size. */
for (const [name, viewport] of Object.entries({ 'a laptop': { width: 1440, height: 900 }, 'a tablet': { width: 1024, height: 768 }, 'a phone': { width: 390, height: 844 }, 'a small phone': { width: 320, height: 568 } })) {
    test(`the bar over a selected picture opens whole: ${name}`, async ({ page }) => {
        await page.setViewportSize(viewport);
        const student = makeStudentWithEverything();
        await openStudentHome(page, student.email);
        await page.goto(`/workspaces/${student.workspace}/notes/${student.topNote}`);
        await page.locator('[data-note-editor][data-ready]').waitFor();
        await page.locator('.note-prose').click();
        await page.keyboard.press('Control+Shift+i');
        const chooser = page.waitForEvent('filechooser');
        await page.getByRole('dialog', { name: 'Add a picture' }).getByRole('button', { name: 'Choose a picture' }).click();
        await (await chooser).setFiles({ name: 'cell.png', mimeType: 'image/png', buffer: picture(300, 180) });
        const image = page.locator('.note-prose figure[data-note-image] img');
        await expect(image).toBeVisible();
        await expect.poll(() => image.evaluate((img) => img.naturalWidth)).toBe(300);

        const bar = page.locator('#note-image-toolbar');
        // Where the picture lands, and scrolled up under the top bar, where the bar has no room above it.
        for (const where of ['as it landed', 'scrolled to the top of the window']) {
            if (where !== 'as it landed') await image.evaluate((img) => window.scrollBy(0, img.getBoundingClientRect().top - 70));
            const box = await image.boundingBox();
            await page.mouse.click(box.x + box.width / 2, Math.max(box.y + box.height / 2, 120));
            await expect(bar, `the bar, picture ${where}`).toBeVisible();
            const problems = await bar.evaluate((el) => {
                const r = el.getBoundingClientRect();
                const found = [];
                if (r.left < -0.5 || r.top < -0.5 || r.right > innerWidth + 0.5 || r.bottom > innerHeight + 0.5) {
                    found.push(`outside the window (${Math.round(r.left)},${Math.round(r.top)} to ${Math.round(r.right)},${Math.round(r.bottom)} in ${innerWidth}x${innerHeight})`);
                }
                const lost = [];
                for (const x of [r.left + 5, r.left + r.width / 2, r.right - 5]) {
                    for (const y of [r.top + 5, r.bottom - 5]) {
                        const top = document.elementFromPoint(x, y);
                        if (!top || !el.contains(top)) lost.push(`${Math.round(x)},${Math.round(y)} is ${top?.className?.toString().slice(0, 30) || top?.tagName}`);
                    }
                }
                if (lost.length) found.push(`covered or clipped at ${lost.join('; ')}`);
                // Its words are whole: the caption button is as wide as its word.
                if (el.scrollWidth > el.clientWidth + 1) found.push('its buttons are cut off sideways');
                return found;
            });
            expect(problems, `the bar, picture ${where}`).toEqual([]);
        }
    });
}
