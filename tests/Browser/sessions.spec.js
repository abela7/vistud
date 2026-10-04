import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { foreignColours, makeStudentWithPomodoro, makeStudentWithSession, makeStudentWithTopics, openStudentHome, THEMES, useSentinelTheme, useTheme } from './support.js';

/* Study sessions and their clock (docs/specs/study-memory.md §4). */

const desktop = { width: 1440, height: 900 };
const phone = { width: 390, height: 844 };
const analyse = async (page) => (await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze())
    .violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target).join(' ')}`);
const clock = (page) => page.locator('.session-time [data-clock]');
const pill = (page) => page.locator('.session-pill');

test.use({ reducedMotion: 'reduce' });
test.describe.configure({ timeout: 60_000 });

async function openSession(page) {
    const student = makeStudentWithSession();
    await openStudentHome(page, student.email);
    await page.goto(`/workspaces/${student.workspace}/sessions/${student.session}`);
    await page.getByRole('heading', { level: 1, name: 'Joins' }).waitFor();
    await page.waitForLoadState('load');
    return student;
}

test('a session is started from the Overview, and its clock runs, pauses, breaks and ends', async ({ page }) => {
    await page.setViewportSize(desktop);
    const student = makeStudentWithTopics();
    await openStudentHome(page, student.email);
    await page.goto(`/workspaces/${student.workspace}`);
    await page.getByRole('heading', { level: 1, name: 'Databases' }).waitFor();
    await page.waitForLoadState('load');
    await expect(pill(page)).toHaveCount(0);

    await page.getByRole('button', { name: 'Start studying' }).first().click();
    const dialog = page.locator('#study-dialog');
    await dialog.getByLabel('Topic (optional)').selectOption({ label: 'Normalisation' });
    await dialog.getByRole('button', { name: 'Start' }).click();
    await page.getByRole('heading', { level: 1, name: 'Normalisation' }).waitFor();

    // The clock ticks here and in the top bar.
    await expect(clock(page)).not.toHaveText('0:00:00', { timeout: 5000 });
    await expect(pill(page)).toContainText('Databases');

    await page.getByRole('button', { name: 'Pause', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Resume', exact: true })).toBeVisible();
    await expect(pill(page).getByRole('button', { name: 'Resume the session' })).toBeVisible();
    const paused = await clock(page).textContent();
    await page.waitForTimeout(1500);
    await expect(clock(page)).toHaveText(paused);

    await page.getByRole('button', { name: 'Take a break' }).click();
    await expect(page.locator('.session-clock')).toContainText('On a break');
    await page.getByRole('button', { name: 'Back to studying' }).click();
    await expect(page.locator('.session-clock')).toContainText('Studying');

    await page.getByRole('button', { name: 'End session' }).click();
    const end = page.locator('#session-dialog');
    await end.getByLabel('Understood').check();
    await end.getByRole('button', { name: 'End session' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Session ended.' })).toBeVisible();
    await expect(pill(page)).toHaveCount(0);
    await expect(page.locator('h1 + .status-chip')).toHaveText('Understood');
    await expect(page.getByRole('region', { name: 'What happened' })).toContainText('Studied');
});

test('the session page is an open space: a slim clock, what to do as tiles, and the top bar follows it on every page', async ({ page }) => {
    await page.setViewportSize(desktop);
    const student = await openSession(page);
    const tiles = page.getByRole('region', { name: 'What to do' });
    for (const name of ['Another AI', 'Save from the chat', 'Ask a question', 'New flashcard', 'Write a note', 'Notes & files']) {
        await expect(tiles.getByRole('button', { name, exact: true })).toBeVisible();
    }
    await expect(page.getByRole('region', { name: 'What happened' })).toHaveCount(0);
    expect((await page.locator('.clock-bar').boundingBox()).height).toBeLessThan(120);

    await tiles.getByRole('button', { name: 'Notes & files', exact: true }).click();
    const panel = page.locator('#session-dialog');
    await expect(panel.getByRole('list', { name: 'In Week 1: Relational model' })).toContainText('Lecture 3: joins');
    const search = panel.getByRole('searchbox', { name: /Search the notes and files in Week 1/ });
    await search.fill('zzz');
    await expect(panel.getByText('Nothing matches.')).toBeVisible();
    await expect(panel.getByRole('link', { name: 'Lecture 3: joins' })).toBeHidden();
    await search.fill('LECTURE');
    await expect(panel.getByRole('link', { name: 'Lecture 3: joins' })).toBeVisible();
    await expect(panel.getByText('Nothing matches.')).toBeHidden();
    await page.locator('#session-dialog').getByRole('button', { name: 'Done' }).click();
    await page.locator('#session-dialog').waitFor({ state: 'hidden' });

    await page.goto(`/workspaces/${student.workspace}/progress`);
    await expect(pill(page)).toBeVisible();
    await pill(page).getByRole('button', { name: 'Pause the session' }).click();
    await expect(pill(page).getByRole('button', { name: 'Resume the session' })).toBeVisible();
    await pill(page).getByRole('link').click();
    await page.getByRole('heading', { level: 1, name: 'Joins' }).waitFor();
    await expect(page.locator('.session-clock')).toContainText('Paused');
});

test('another tab follows a pause straight away', async ({ page, context }) => {
    await page.setViewportSize(desktop);
    const student = await openSession(page);
    const other = await context.newPage();
    await other.goto(`/workspaces/${student.workspace}`);
    await other.waitForLoadState('load');
    await expect(pill(other).getByRole('button', { name: 'Pause the session' })).toBeVisible();

    await page.getByRole('button', { name: 'Pause', exact: true }).click();
    await expect(pill(other).getByRole('button', { name: 'Resume the session' })).toBeVisible({ timeout: 5000 });
});

async function openPomodoro(page, secondsLeft) {
    const student = makeStudentWithPomodoro(secondsLeft);
    await openStudentHome(page, student.email);
    await page.goto(`/workspaces/${student.workspace}/sessions/${student.session}`);
    await page.getByRole('heading', { level: 1, name: 'Joins' }).waitFor();
    await page.waitForLoadState('load');
    return student;
}

test('a Pomodoro focus period runs out on its own and the break begins, here and in the top bar', async ({ page }) => {
    await page.setViewportSize(desktop);
    await openPomodoro(page, 4);
    const card = page.getByRole('region', { name: 'Pomodoro clock' });
    await expect(card).toContainText('Focus 1 of 4');
    await expect(page).toHaveTitle(/Focus · Study session/);

    await expect(card).toContainText('Short break', { timeout: 15000 });
    await expect(card).toContainText('1 pomodoro');
    await expect(card.getByRole('img', { name: '1 of 4 pomodoros before the long break' })).toBeVisible();
    await expect(pill(page)).toContainText('00:5');
    await expect(page).toHaveTitle(/Break · Study session/);
    expect(await page.evaluate(() => localStorage.getItem('vistud.pomodoro.announced'))).toContain(':0:0:focus');

    await card.getByRole('button', { name: 'Skip break' }).click();
    await expect(card).toContainText('Focus 2 of 4');
});

test('a Pomodoro session is started from the Overview, and the sound can be turned off', async ({ page }) => {
    await page.setViewportSize(desktop);
    const student = makeStudentWithTopics();
    await openStudentHome(page, student.email);
    await page.goto(`/workspaces/${student.workspace}`);
    await page.waitForLoadState('load');
    await page.getByRole('button', { name: 'Start studying' }).first().click();
    const dialog = page.locator('#study-dialog');
    await dialog.getByLabel('Pomodoro').check();
    await dialog.getByLabel('Rhythm').selectOption('short');
    await dialog.getByRole('button', { name: 'Start' }).click();
    const card = page.getByRole('region', { name: 'Pomodoro clock' });
    await expect(card).toContainText('Focus 1 of 4');
    await expect(card.locator('[data-countdown]')).not.toHaveText('15:00', { timeout: 5000 });

    const sound = card.getByRole('button', { name: 'Sound' });
    await expect(sound).toHaveAttribute('aria-pressed', 'true');
    await sound.click();
    await expect(sound).toHaveAttribute('aria-pressed', 'false');
    expect(await page.evaluate(() => localStorage.getItem('vistud.pomodoro.sound'))).toBe('false');
});

for (const [name, viewport] of Object.entries({ desktop, phone })) {
    test(`every colour of the Pomodoro clock comes from a token: ${name}`, async ({ page }) => {
        await page.setViewportSize(viewport);
        await openPomodoro(page, 200);
        await useSentinelTheme(page);
        expect(await foreignColours(page), 'Pomodoro clock: colours not from a token').toEqual([]);
    });
}

for (const theme of THEMES) {
    test(`axe finds no violations on the Pomodoro clock: ${theme}`, async ({ page }) => {
        await page.setViewportSize(desktop);
        await openPomodoro(page, 200);
        await useTheme(page, theme);
        expect(await analyse(page)).toEqual([]);
    });
}

for (const [name, viewport] of Object.entries({ desktop, phone })) {
    test(`every colour of a session comes from a token: ${name}`, async ({ page }) => {
        await page.setViewportSize(viewport);
        const student = await openSession(page);
        await useSentinelTheme(page);
        const states = { 'session page': await foreignColours(page) };
        await page.getByRole('button', { name: 'End session' }).click();
        await page.locator('#session-dialog').getByRole('heading').waitFor();
        states['end dialog'] = await foreignColours(page);
        await page.goto(`/workspaces/${student.workspace}`);
        await useSentinelTheme(page);
        states['overview with study time'] = await foreignColours(page);
        for (const [state, colours] of Object.entries(states)) {
            expect(colours, `${state}: colours not from a token`).toEqual([]);
        }
    });
}

for (const theme of THEMES) {
    test(`axe finds no violations on a session: ${theme}`, async ({ page }) => {
        await page.setViewportSize(desktop);
        const student = await openSession(page);
        await useTheme(page, theme);
        expect(await analyse(page)).toEqual([]);
        await page.getByRole('button', { name: 'End session' }).click();
        await page.locator('#session-dialog').getByRole('heading').waitFor();
        expect(await analyse(page)).toEqual([]);
        await page.goto(`/workspaces/${student.workspace}`);
        await useTheme(page, theme);
        expect(await analyse(page)).toEqual([]);
    });
}

test('a session page never scrolls sideways at 320 px, even with 200% text', async ({ page }) => {
    await openSession(page);
    await page.setViewportSize({ width: 320, height: 800 });
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
});

test('starting while a session is open says so, and it can be ended first', async ({ page }) => {
    await page.setViewportSize(desktop);
    const student = await openSession(page);
    await page.goto(`/workspaces/${student.workspace}/modules`);
    await page.locator('main').getByRole('link', { name: 'Week 1: Relational model' }).click();
    await page.getByRole('heading', { level: 1, name: 'Week 1: Relational model' }).waitFor();
    await page.waitForLoadState('load');
    await page.getByRole('button', { name: 'Study this' }).click();
    const panel = page.locator('#study-dialog');
    await expect(panel.getByRole('heading', { name: "You're already studying" })).toBeVisible();
    await expect(panel).toContainText('Joins');
    await expect(panel.getByRole('link', { name: 'Go to the session' })).toHaveAttribute('href', new RegExp(`/sessions/${student.session}$`));
    await panel.getByRole('button', { name: 'End it' }).click();
    await expect(panel.getByRole('heading', { name: 'Start studying' })).toBeVisible();
    await expect(page.getByRole('status').filter({ hasText: 'Session ended.' })).toBeVisible();
    await expect(pill(page)).toHaveCount(0);
});

test('the top-bar timer hides to a pulsing dot, and stays hidden until shown again', async ({ page }) => {
    await page.setViewportSize(desktop);
    const student = await openSession(page);
    await pill(page).getByRole('button', { name: 'Hide the timer' }).click();
    await expect(pill(page)).toBeHidden();
    const dot = page.getByRole('button', { name: 'Show the study timer' });
    await expect(dot).toBeVisible();
    await page.goto(`/workspaces/${student.workspace}`);
    await expect(dot).toBeVisible();
    await expect(pill(page)).toBeHidden();
    await dot.click();
    await expect(pill(page)).toBeVisible();
    await expect(pill(page).getByRole('button', { name: 'Pause the session' })).toBeVisible();
});

test('the session topic is changed on the page, to one of the module or a new one', async ({ page }) => {
    await page.setViewportSize(desktop);
    await openSession(page);
    await page.getByRole('button', { name: 'Change topic' }).click();
    const panel = page.locator('#session-dialog');
    await expect(panel.getByRole('heading', { name: 'What this session is about' })).toBeVisible();
    await panel.getByLabel('Topic', { exact: true }).selectOption('new');
    await panel.getByLabel('Name').fill('Outer joins');
    expect(await analyse(page)).toEqual([]);
    await page.screenshot({ path: 'test-results/session-topic-panel.png' });
    await panel.getByRole('button', { name: 'Save' }).click();
    await expect(panel).not.toBeVisible();
    await expect(page.getByRole('heading', { level: 1, name: 'Outer joins' })).toBeVisible();
    await page.screenshot({ path: 'test-results/session-topic-set.png' });
});
