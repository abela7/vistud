import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';
import { makeStudentWithModules, openStudentHome } from './support.js';

/* The picture viewer on a file's page: any shape fits its frame whole, and zoom, real size, turn and move work (the owner's review, 2026-10-04). */

test.use({ reducedMotion: 'reduce' });

/** A student with a tall photo (1080 × 2340), a wide one (3000 × 1000) and a tiny icon (64 × 64): the pages of the three. */
function pictures(email) {
    const code = [
        `$p = app(\\App\\Identity\\PrincipalFactory::class)->forUser(\\App\\Models\\User::query()->where('email', '${email}')->firstOrFail(), 'web');`,
        `$w = collect(app(\\App\\Study\\Workspaces::class)->list($p))->first(); $m = collect(app(\\App\\Study\\Modules::class)->list($p, $w->id))->first();`,
        `$make = function (string $name, int $width, int $height, string $type) use ($p, $w, $m) {`,
        `  $im = imagecreatetruecolor($width, $height);`,
        `  for ($y = 0; $y < $height; $y += 20) { for ($x = 0; $x < $width; $x += 20) { imagefilledrectangle($im, $x, $y, $x + 19, $y + 19, imagecolorallocate($im, (int) ($x * 255 / $width), (int) ($y * 255 / $height), 128)); } }`,
        `  $tmp = tempnam(sys_get_temp_dir(), 'pic'); $type === 'png' ? imagepng($im, $tmp) : imagejpeg($im, $tmp, 80);`,
        `  $f = app(\\App\\Study\\Files::class)->upload($p, 'module', $m->id, $tmp, $name);`,
        `  return route('workspaces.files.show', [$w->id, $f->id]); };`,
        `echo $make('IMIE.jpg', 1080, 2340, 'jpg').'|'.$make('Banner.png', 3000, 1000, 'png').'|'.$make('Icon.png', 64, 64, 'png');`,
    ].join(' ');
    const [tall, wide, tiny] = execFileSync('php', ['artisan', 'tinker', '--execute', code], { cwd: process.cwd(), maxBuffer: 64 * 1024 * 1024 }).toString().trim().split('\n').pop().split('|').map((u) => new URL(u).pathname);

    return { tall, wide, tiny };
}

const boxes = async (page) => {
    const stage = await page.locator('.image-stage').boundingBox();
    const picture = await page.locator('.image-stage img').boundingBox();

    return { stage, picture };
};
const inside = ({ stage, picture }) => picture.x >= stage.x - 1 && picture.y >= stage.y - 1 && picture.x + picture.width <= stage.x + stage.width + 1 && picture.y + picture.height <= stage.y + stage.height + 1;
const percent = async (page) => Number.parseInt((await page.locator('.image-zoom').innerText()).replace('%', ''), 10);

for (const [name, size] of [['computer', { width: 1440, height: 900 }], ['phone', { width: 390, height: 844 }]]) {
    test(`a tall, a wide and a tiny picture are shown whole in the frame, and zoom, real size, turn and move work (${name})`, async ({ page }) => {
        test.setTimeout(120_000);
        await page.setViewportSize(size);
        const email = makeStudentWithModules();
        const { tall, wide, tiny } = pictures(email);
        await openStudentHome(page, email);

        // A tall photo is no longer cut off: all of it is in the frame, fitted, and says its size.
        await page.goto(tall);
        await expect(page.locator('.image-stage img.is-ready')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Fit', exact: true })).toHaveAttribute('aria-pressed', 'true');
        expect(inside(await boxes(page))).toBe(true);
        // And the frame itself is in the window, down to its bottom edge: no part of the picture waits below the fold.
        const frame = await page.locator('.file-preview-image').boundingBox();
        expect(frame.y + frame.height).toBeLessThanOrEqual(size.height + 1);
        await expect(page.locator('.image-info')).toContainText('JPEG');
        await expect(page.locator('.image-info')).toContainText('1080 × 2340 px');
        const fitted = await percent(page);
        expect(fitted).toBeLessThan(100);

        // Bigger and smaller.
        await page.getByRole('button', { name: 'Zoom in' }).click();
        expect(await percent(page)).toBeGreaterThan(fitted);
        await expect(page.getByRole('button', { name: 'Fit', exact: true })).toHaveAttribute('aria-pressed', 'false');
        await page.getByRole('button', { name: 'Zoom out' }).click();
        expect(await percent(page)).toBeLessThanOrEqual(fitted + 1);

        // Real size: 1:1, at its top, and moved by a drag; it can't be dragged out of the frame.
        await page.getByRole('button', { name: /^1:1/ }).click();
        expect(await percent(page)).toBe(100);
        let { stage, picture } = await boxes(page);
        expect(Math.round(picture.height)).toBe(2340);
        expect(Math.round(picture.y)).toBe(Math.round(stage.y));
        await page.mouse.move(stage.x + stage.width / 2, stage.y + stage.height / 2);
        await page.mouse.down();
        await page.mouse.move(stage.x + stage.width / 2, stage.y + stage.height / 2 - 300, { steps: 5 });
        await page.mouse.up();
        const moved = (await boxes(page)).picture;
        expect(Math.round(stage.y - moved.y)).toBe(300);
        await page.mouse.move(stage.x + 10, stage.y + 10);
        await page.mouse.down();
        await page.mouse.move(stage.x + 10, stage.y + 6000, { steps: 4 });
        await page.mouse.up();
        expect(Math.round((await boxes(page)).picture.y)).toBe(Math.round(stage.y));

        // The width of the frame, from its top; then the keyboard: 0 fits, + and - zoom, R turns.
        await page.getByRole('button', { name: /^Width/ }).click();
        ({ stage, picture } = await boxes(page));
        expect(Math.abs(picture.width - stage.width)).toBeLessThan(2);
        await page.locator('.image-stage').focus();
        await page.keyboard.press('0');
        expect(inside(await boxes(page))).toBe(true);
        await page.keyboard.press('+');
        expect(await percent(page)).toBeGreaterThan(fitted);
        await page.keyboard.press('0');

        // Turned a quarter: it is on its side, and still whole in the frame.
        const before = (await boxes(page)).picture;
        await page.getByRole('button', { name: /Turn the picture/ }).click();
        const turned = await boxes(page);
        expect(inside(turned)).toBe(true);
        // On its side: wider than tall now, where it was taller than wide.
        expect(before.height).toBeGreaterThan(before.width);
        expect(turned.picture.width).toBeGreaterThan(turned.picture.height);

        // Ctrl and the wheel zoom too.
        const turnedPercent = await percent(page);
        await page.mouse.move(turned.stage.x + turned.stage.width / 2, turned.stage.y + turned.stage.height / 2);
        await page.keyboard.down('Control');
        await page.mouse.wheel(0, -100);
        await page.keyboard.up('Control');
        await expect.poll(() => percent(page)).toBeGreaterThan(turnedPercent);

        // A wide one is whole in the frame, and a tiny one is not blown up.
        await page.goto(wide);
        await expect(page.locator('.image-stage img.is-ready')).toBeVisible();
        expect(inside(await boxes(page))).toBe(true);
        await page.goto(tiny);
        await expect(page.locator('.image-stage img.is-ready')).toBeVisible();
        expect(await percent(page)).toBe(100);
        expect(inside(await boxes(page))).toBe(true);
        await page.screenshot({ path: `test-results/image-viewer-${name}.png` });

        // Nothing scrolls sideways.
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1)).toBe(true);
    });
}

test('the picture viewer has no accessibility violations, at 320 px and 200% text too', async ({ page }) => {
    test.setTimeout(90_000);
    await page.setViewportSize({ width: 1280, height: 800 });
    const email = makeStudentWithModules();
    const { tall } = pictures(email);
    await openStudentHome(page, email);
    await page.goto(tall);
    await expect(page.locator('.image-stage img.is-ready')).toBeVisible();
    const analyse = async () => (await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze())
        .violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target).join(' ')}`);
    expect(await analyse()).toEqual([]);
    await page.getByRole('button', { name: /^1:1/ }).click();
    expect(await analyse()).toEqual([]);
    await page.setViewportSize({ width: 320, height: 640 });
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
});
