import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { foreignColours, makeStudentWithProgressTree, openSection, openStudentHome, THEMES, useSentinelTheme, useTheme } from './support.js';

/* Progress, the tree: the ring, the filters, modules that fold, topics with where they stand (docs/specs/vistud-2-blueprint.md §3.5.5, §3.8). */

const desktop = { width: 1440, height: 900 };
const phone = { width: 390, height: 844 };
const dialog = (page) => page.locator('#progress-dialog');
const sheet = (page) => page.locator('#topic-sheet');
const row = (page, name) => page.locator('.topic-row').filter({ has: page.getByRole('button', { name, exact: true }) });
const folded = (page, module) => page.getByRole('button', { name: new RegExp(`^${module}`) });
const analyse = async (page) => (await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze())
    .violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target).join(' ')}`);

test.use({ reducedMotion: 'reduce' });
test.describe.configure({ timeout: 60_000 });

async function openProgress(page, query = '') {
    const student = makeStudentWithProgressTree();
    await openStudentHome(page, student.email);
    await page.goto(`/courses/${student.workspace}/progress${query}`);
    await page.getByRole('heading', { level: 1, name: 'Progress' }).waitFor();
    await page.waitForLoadState('load');
    return student;
}

test('Progress is a tree: the ring, the filters, and the module the student is in open', async ({ page }) => {
    await page.setViewportSize(desktop);
    await openProgress(page);

    // The ring and the number: ACID (set by the tutor) and Joins are understood, out of five.
    await expect(page.getByRole('progressbar', { name: 'Topics understood', exact: true })).toHaveAttribute('aria-valuenow', '40');
    await expect(page.locator('.progress-facts')).toContainText('40 % · 2 of 5 topics');
    const filters = page.getByRole('group', { name: 'Show topics', exact: true });
    await expect(filters.getByRole('button')).toHaveText([/^All\s+5$/, /^Needs attention\s+1$/, /^Not started\s+2$/, /^Mastered\s+0$/]);

    // Week 1 is the one the student is in: open. The rest are folded, and open on a tap.
    await expect(row(page, 'Joins')).toBeVisible();
    await expect(row(page, 'Primary and foreign keys')).toBeVisible();
    await expect(folded(page, 'Week 1')).toHaveAttribute('aria-expanded', 'true');
    await expect(folded(page, 'Week 2')).toHaveAttribute('aria-expanded', 'false');
    await expect(row(page, 'ACID')).toBeHidden();
    await expect(page.getByRole('progressbar', { name: /^Topics understood in Week 1/ })).toHaveAttribute('aria-valuenow', '1');
    await folded(page, 'Week 2').click();
    await expect(folded(page, 'Week 2')).toHaveAttribute('aria-expanded', 'true');
    await expect(row(page, 'ACID')).toBeVisible();
    await expect(row(page, 'ACID')).toContainText('Understood');
    await folded(page, 'Week 2').click();
    await expect(row(page, 'ACID')).toBeHidden();

    // A topic says where it stands, and the one confusing for a week says why it needs a look.
    await expect(row(page, 'Primary and foreign keys')).toContainText('Still confusing');
    await expect(row(page, 'Primary and foreign keys')).toContainText(/since \d{1,2} [A-Z][a-z]{2}/);
    await expect(row(page, 'Primary and foreign keys').locator('.tree-attention')).toContainText('Confusing for over a week');
    await expect(row(page, 'Joins')).not.toContainText('Evidence');
    await expect(row(page, 'Joins').locator('.status-chip')).toHaveAttribute('title', /.+/);
    await expect(page.getByText('Evidence:')).toHaveCount(0);
    await expect(page.getByRole('button', { name: /findings?$/ })).toHaveCount(0);

    // The filters: what needs attention, then what is not started.
    await filters.getByRole('button', { name: /^Needs attention/ }).click();
    await expect(page).toHaveURL(/filter=attention/);
    await expect(filters.getByRole('button', { name: /^Needs attention/ })).toHaveAttribute('aria-pressed', 'true');
    await expect(page.locator('.topic-row:visible')).toHaveCount(1);
    await expect(row(page, 'Primary and foreign keys')).toBeVisible();
    await filters.getByRole('button', { name: /^Not started/ }).click();
    await expect(page.locator('.topic-row:visible')).toHaveCount(2);
    await expect(row(page, 'Isolation levels')).toBeVisible();
    await expect(row(page, 'Normalisation')).toBeVisible();
    await filters.getByRole('button', { name: /^Mastered/ }).click();
    await expect(page.getByText('Nothing is mastered yet.')).toBeVisible();
    await filters.getByRole('button', { name: /^All/ }).click();
    await expect(page.locator('.topic-row:visible')).toHaveCount(2);
});

test('the course home says the same, and its Next line sends the student to the topic that needs a look', async ({ page }) => {
    await page.setViewportSize(desktop);
    const student = await openProgress(page);
    await page.goto(`/courses/${student.workspace}`);
    await page.getByRole('heading', { level: 1, name: 'Databases' }).waitFor();
    await expect(page.getByRole('progressbar', { name: 'Topics understood', exact: true })).toHaveAttribute('aria-valuenow', '40');
    await expect(page.locator('.progress-facts')).toContainText('2 of 5 topics');
    await expect(page.getByRole('link', { name: '1 topic needs another look' })).toHaveAttribute('href', /filter=attention/);
    await page.getByRole('link', { name: '1 topic needs another look' }).click();
    await page.getByRole('heading', { level: 1, name: 'Progress' }).waitFor();
    await expect(page.locator('.topic-row:visible')).toHaveCount(1);
});

test('a topic opens its sheet: who set the status, key points, and removing it', async ({ page }) => {
    await page.setViewportSize(desktop);
    await openProgress(page);
    await folded(page, 'Week 2').click();

    await row(page, 'ACID').getByRole('button', { name: 'ACID', exact: true }).click();
    await expect(sheet(page).getByRole('heading', { name: 'ACID' })).toBeVisible();
    await expect(sheet(page)).toContainText('Set by the tutor');
    await sheet(page).getByLabel('New key point').fill('Atomic means all or nothing.');
    await sheet(page).getByRole('button', { name: 'Add', exact: true }).click();
    await expect(sheet(page)).toContainText('Key point added.');
    await expect(sheet(page).getByRole('region', { name: 'Key points' })).toContainText('Atomic means all or nothing.');
    await sheet(page).getByRole('button', { name: /^Remove key point: Atomic/ }).click();
    await expect(sheet(page)).toContainText('Key point removed.');

    // The student's own word replaces the tutor's.
    await sheet(page).locator('.status-choice', { hasText: 'Still confusing' }).click();
    await expect(sheet(page)).toContainText('Saved.');
    await expect(sheet(page)).not.toContainText('Set by the tutor');
    await sheet(page).getByRole('button', { name: 'Close', exact: true }).last().click();
    await expect(row(page, 'ACID')).toContainText('Still confusing');
    await expect(page.getByRole('progressbar', { name: 'Topics understood', exact: true })).toHaveAttribute('aria-valuenow', '20');

    // Removing asks first, and says what stays.
    await row(page, 'Isolation levels').getByRole('button', { name: 'Isolation levels', exact: true }).click();
    await sheet(page).getByRole('button', { name: 'Remove topic' }).click();
    await expect(sheet(page)).toContainText('What you did on it stays in your study record.');
    await sheet(page).getByRole('button', { name: 'Keep it' }).click();
    await expect(sheet(page).getByRole('button', { name: 'Remove topic' })).toBeVisible();
    await sheet(page).getByRole('button', { name: 'Remove topic' }).click();
    await sheet(page).getByRole('button', { name: 'Remove', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Isolation levels', exact: true })).toHaveCount(0);
});

test('Study on a row starts the session on that topic', async ({ page }) => {
    await page.setViewportSize(desktop);
    await openProgress(page);
    await page.getByRole('button', { name: 'Study Joins' }).click();
    await page.waitForURL(/\/sessions\//);
    await expect(page.getByRole('heading', { level: 1, name: 'Joins' })).toBeVisible();
});

test('a student adds a topic, selects several and tells the app they are covered, and writes a question on the Questions page', async ({ page }) => {
    await page.setViewportSize(desktop);
    await openProgress(page);

    await page.getByRole('button', { name: 'New topic' }).first().click();
    await expect(dialog(page).getByLabel('Name')).toBeFocused();
    await dialog(page).getByLabel('Name').fill('Transactions');
    await dialog(page).getByLabel('Module').selectOption({ label: 'Week 1: Relational model' });
    await dialog(page).getByRole('button', { name: 'Add topic' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Transactions is added.' })).toBeVisible();
    await expect(row(page, 'Transactions')).toContainText('Not started');

    // Select mode opens every module; the bar sets a status for the ones picked.
    await page.locator('.progress-head').getByRole('button', { name: 'Select', exact: true }).click();
    await row(page, 'Normalisation').getByTitle('Select Normalisation').click();
    await expect(row(page, 'Normalisation')).toHaveClass(/is-selected/);
    await page.getByRole('button', { name: 'Covered', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: '1 topic marked Covered.' })).toBeVisible();
    await expect(row(page, 'Normalisation')).toContainText('Covered');
    await page.locator('.progress-head').getByRole('button', { name: 'Done', exact: true }).click();

    // Questions are written down on their own page, one line at a time.
    await openSection(page, 'Questions');
    await page.getByRole('heading', { level: 1, name: 'Questions' }).waitFor();
    const questions = page.getByRole('list', { name: 'Questions' });
    const line = page.getByPlaceholder("What don't you get? Write it down…");
    await line.fill('What does a foreign key point at?');
    await line.press('Enter');
    await expect(questions).toContainText('What does a foreign key point at?');
});

test('a topic moves up and down in its module, and to another one', async ({ page }) => {
    await page.setViewportSize(desktop);
    await openProgress(page);
    await row(page, 'Primary and foreign keys').getByRole('button', { name: /^Actions for/ }).click();
    await row(page, 'Primary and foreign keys').getByRole('button', { name: 'Move up' }).click();
    await expect(page.locator('.topic-row:visible .tree-topic-name').first()).toHaveText('Primary and foreign keys');
    await row(page, 'Primary and foreign keys').getByRole('button', { name: /^Actions for/ }).click();
    await row(page, 'Primary and foreign keys').getByRole('button', { name: 'Move to module…' }).click();
    await expect(dialog(page).getByLabel('Module')).toBeFocused();
    await dialog(page).getByLabel('Module').selectOption({ label: 'Week 2: Transactions' });
    await dialog(page).getByRole('button', { name: 'Move' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Primary and foreign keys is moved.' })).toBeVisible();
    await expect(page.getByRole('progressbar', { name: /^Topics understood in Week 1/ })).toHaveAttribute('aria-valuemax', '1');
});

for (const [name, viewport] of Object.entries({ desktop, phone })) {
    test(`every colour in Progress comes from a token: ${name}`, async ({ page }) => {
        await page.setViewportSize(viewport);
        await openProgress(page);
        await folded(page, 'Week 2').click();
        await useSentinelTheme(page);
        const states = { tree: await foreignColours(page) };
        await row(page, 'ACID').getByRole('button', { name: 'ACID', exact: true }).click();
        await sheet(page).getByRole('heading', { name: 'ACID' }).waitFor();
        states['topic sheet'] = await foreignColours(page);
        for (const [state, colours] of Object.entries(states)) {
            expect(colours, `${state}: colours not from a token`).toEqual([]);
        }
    });
}

for (const theme of THEMES) {
    test(`axe finds no violations in Progress: ${theme}`, async ({ page }) => {
        await page.setViewportSize(desktop);
        await openProgress(page);
        await folded(page, 'Week 2').click();
        await useTheme(page, theme);
        expect(await analyse(page)).toEqual([]);
        await row(page, 'ACID').getByRole('button', { name: 'ACID', exact: true }).click();
        await sheet(page).getByRole('heading', { name: 'ACID' }).waitFor();
        expect(await analyse(page)).toEqual([]);
        await sheet(page).getByRole('button', { name: 'Close', exact: true }).last().click();
        await page.getByRole('button', { name: 'New topic' }).first().click();
        await dialog(page).getByLabel('Name').waitFor();
        expect(await analyse(page)).toEqual([]);
    });
}

test('Progress never scrolls sideways at 320 px, even with 200% text', async ({ page }) => {
    await openProgress(page);
    await folded(page, 'Week 2').click();
    await page.setViewportSize({ width: 320, height: 800 });
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
});

test('on a phone the tree is one column and the topic sheet fits', async ({ page }) => {
    await page.setViewportSize(phone);
    await openProgress(page);
    await expect(row(page, 'Joins')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Study Joins' })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
    await row(page, 'Joins').getByRole('button', { name: 'Joins', exact: true }).click();
    await expect(sheet(page).getByRole('heading', { name: 'Joins' })).toBeVisible();
    const box = await sheet(page).locator('.modal-panel').boundingBox();
    expect(box.width).toBeLessThanOrEqual(390);
});
