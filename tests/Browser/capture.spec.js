import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { foreignColours, makeStudentWithSession, openStudentHome, THEMES, useSentinelTheme, useTheme } from './support.js';

/* Save from the chat: the tutor's marks, pasted, reviewed and saved (docs/specs/study-memory.md §4.4). */

const desktop = { width: 1440, height: 900 };
const phone = { width: 390, height: 844 };
const dialog = (page) => page.locator('#capture-dialog');
const analyse = async (page) => (await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze())
    .violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target).join(' ')}`);

const CHAT = `Tutor: **Slide 3 of 12 · Left joins**
A LEFT JOIN keeps every row of the left table.
<finding topic="Joins">A LEFT JOIN keeps every row of the left table.</finding>
<question topic="Joins">Why are unmatched columns NULL rather than empty?</question>
<flashcard topic="Joins"><front>What does a LEFT JOIN keep?</front><back>Every row of the left table.</back></flashcard>
<attempt topic="Joins" form="apply" support="unaided" result="correct"><asked>Which rows does customers LEFT JOIN orders return?</asked><answer>All customers, with NULLs where there are no orders.</answer></attempt>
<checkpoint>Slide 7 of 12. Covered left joins; next is self joins.</checkpoint>
<summary>We covered inner and left joins. Left joins went well; NULLs were confusing at first.</summary>
<status topic="Joins" proposed="understood">Answered the apply question right, unaided.</status>`;

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

async function review(page) {
    await page.getByRole('button', { name: 'Save from the chat' }).click();
    await dialog(page).getByLabel('The tutor\'s replies').fill(CHAT);
    await dialog(page).getByRole('button', { name: 'Find the marks' }).click();
    await dialog(page).getByRole('heading', { name: /Statuses: you decide/ }).waitFor();
}

test('the tutor\'s marks are pasted, reviewed and saved where they belong', async ({ page }) => {
    await page.setViewportSize(desktop);
    const student = await openSession(page);
    await review(page);

    await expect(dialog(page).getByRole('checkbox', { name: /^Set Joins to understood/ })).not.toBeChecked();
    await expect(dialog(page).getByRole('button', { name: 'Save 6' })).toBeVisible();
    await dialog(page).getByRole('checkbox', { name: /^Set Joins to understood/ }).check();
    await dialog(page).getByRole('checkbox', { name: /^Question/ }).uncheck();
    await dialog(page).getByRole('button', { name: 'Save 6' }).click();

    await expect(page.getByRole('status').filter({ hasText: 'Saved 1 key point, 1 flashcard, 1 answer, 1 status, the summary and where the session stands.' })).toBeVisible();
    const tutor = page.getByRole('region', { name: 'From the tutor' });
    await expect(tutor).toContainText('We covered inner and left joins.');
    await expect(tutor).toContainText('Slide 7 of 12.');
    await expect(page.locator('h1 + .status-chip')).toHaveText('Understood');

    // Pasted again, everything saved is recognised; the question can still be saved.
    await review(page);
    await expect(dialog(page).getByText('Saved before').first()).toBeVisible();
    await expect(dialog(page).getByRole('checkbox', { name: /^Question/ })).toBeChecked();
    await dialog(page).getByRole('button', { name: 'Save 1' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Saved 1 question.' })).toBeVisible();

    await page.goto(`/workspaces/${student.workspace}/progress`);
    const joins = page.getByRole('region', { name: 'Topics' }).locator('.topic-row').filter({ has: page.getByText('Joins', { exact: true }) });
    await expect(joins).toContainText('3 findings');
    // The answer is evidence: the rules no longer say "not practised yet".
    await expect(joins).not.toContainText('not practised yet');
    await expect(page.getByRole('list', { name: 'Questions' })).toContainText('Why are unmatched columns NULL rather than empty?');
});

for (const [name, viewport] of Object.entries({ desktop, phone })) {
    test(`every colour of Save from the chat comes from a token: ${name}`, async ({ page }) => {
        await page.setViewportSize(viewport);
        await openSession(page);
        await useSentinelTheme(page);
        await page.getByRole('button', { name: 'Save from the chat' }).click();
        await dialog(page).getByLabel('The tutor\'s replies').waitFor();
        const states = { paste: await foreignColours(page) };
        await dialog(page).getByLabel('The tutor\'s replies').fill(CHAT);
        await dialog(page).getByRole('button', { name: 'Find the marks' }).click();
        await dialog(page).getByRole('heading', { name: /Statuses: you decide/ }).waitFor();
        states.review = await foreignColours(page);
        for (const [state, colours] of Object.entries(states)) {
            expect(colours, `${state}: colours not from a token`).toEqual([]);
        }
    });
}

for (const theme of THEMES) {
    test(`axe finds no violations in Save from the chat: ${theme}`, async ({ page }) => {
        await page.setViewportSize(desktop);
        await openSession(page);
        await useTheme(page, theme);
        await page.getByRole('button', { name: 'Save from the chat' }).click();
        await dialog(page).getByLabel('The tutor\'s replies').waitFor();
        expect(await analyse(page)).toEqual([]);
        await dialog(page).getByLabel('The tutor\'s replies').fill(CHAT);
        await dialog(page).getByRole('button', { name: 'Find the marks' }).click();
        await dialog(page).getByRole('heading', { name: /Statuses: you decide/ }).waitFor();
        expect(await analyse(page)).toEqual([]);
    });
}

test('the review never scrolls sideways at 320 px, even with 200% text', async ({ page }) => {
    await openSession(page);
    await page.setViewportSize({ width: 320, height: 800 });
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    await review(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
    expect(await dialog(page).evaluate((el) => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
});
