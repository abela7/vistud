import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { foreignColours, makeStudentWithCards, makeStudentWithModuleCards, openStudentHome, THEMES, useSentinelTheme, useTheme } from './support.js';

/* Flashcards: the deck, writing cards, making them with an AI, and reviewing (docs/specs/study-memory.md §4.5). */

const desktop = { width: 1440, height: 900 };
const phone = { width: 390, height: 844 };
const analyse = async (page) => (await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze())
    .violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target).join(' ')}`);

const REPLY = `Here are your cards:
<flashcard topic="Joins"><front>What does a LEFT JOIN keep?</front><back>Every row of the left table, with NULLs where the right table has no match.</back></flashcard>
<flashcard topic="Joins"><front>What does a CROSS JOIN make?</front><back>Every pair of rows from the two tables.</back></flashcard>
<flashcard topic="Joins"><front>Why can a LEFT JOIN return more rows than the left table has?</front><back>A left row that matches several right rows appears once for each match.</back></flashcard>`;

test.use({ reducedMotion: 'reduce' });
test.describe.configure({ timeout: 60_000 });

async function openDeck(page) {
    const student = makeStudentWithCards();
    await openStudentHome(page, student.email);
    await page.goto(`/workspaces/${student.workspace}/flashcards`);
    await page.getByRole('heading', { level: 1, name: 'Flashcards' }).waitFor();
    await page.waitForLoadState('load');
    return student;
}

async function openReview(page) {
    const student = makeStudentWithCards();
    await openStudentHome(page, student.email);
    await page.goto(`/workspaces/${student.workspace}/flashcards/review`);
    await page.getByText('Card 1 of 4').waitFor();
    await page.waitForLoadState('load');
    return student;
}

test('cards are written one after another, and a round is reviewed with the keyboard', async ({ page }) => {
    await page.setViewportSize(desktop);
    await openDeck(page);
    await expect(page.getByRole('heading', { name: '4 cards to review today' })).toBeVisible();
    await expect(page.locator('.card-row').filter({ hasText: 'What must a primary key be?' })).toBeVisible();
    await expect(page.locator('.card-row').filter({ hasText: 'What must a primary key be?' })).toContainText('Due tomorrow');
    await expect(page.locator('.card-row').filter({ hasText: 'FULL OUTER JOIN' })).toContainText('Made with an AI');

    // Two cards, one after another, without closing the dialog.
    await page.getByRole('button', { name: 'New card' }).click();
    const editor = page.locator('#flashcard-dialog');
    await editor.getByLabel('Front').fill('What does a self join do?');
    await editor.getByLabel('Back').fill('It joins a table to itself, under two names.');
    await editor.getByLabel('Topic').selectOption({ label: 'Joins' });
    await editor.getByRole('button', { name: 'Save and add another' }).click();
    await expect(editor.getByText('1 card added.')).toBeVisible();
    await expect(editor.getByLabel('Front')).toBeFocused();
    await expect(editor.getByLabel('Topic')).toHaveValue(/.+/);
    await editor.getByLabel('Front').fill('What is a natural join?');
    await editor.getByLabel('Back').fill('A join on every column with the same name in both tables.');
    await editor.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(editor).not.toBeVisible();
    await expect(page.getByRole('heading', { name: '6 cards to review today' })).toBeVisible();

    // The round: Space turns a card over, 1 to 3 answer; a missed card comes back once.
    await page.getByRole('link', { name: /Review now/ }).click();
    await expect(page.getByText('Card 1 of 6')).toBeVisible();
    await expect(page.getByRole('button', { name: /Show the answer/ })).toBeVisible();
    await page.keyboard.press('Space');
    await expect(page.getByRole('group', { name: 'How did it go?' })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Answer' })).toBeFocused();
    await page.keyboard.press('1');
    await expect(page.getByText('Card 2 of 7')).toBeVisible();
    for (const n of [3, 4, 5, 6, 7]) {
        await page.getByRole('button', { name: /Show the answer/ }).click();
        await page.getByRole('button', { name: /^Got it/ }).click();
        await expect(page.getByText(`Card ${n} of 7`)).toBeVisible();
    }
    await expect(page.getByText('Another go at a card you missed.')).toBeVisible();
    await page.keyboard.press('Enter');
    await page.keyboard.press('3');
    await expect(page.getByRole('heading', { name: 'Round done' })).toBeFocused();
    await expect(page.locator('.review-tally')).toContainText('5Got it');
    await expect(page.locator('.review-tally')).toContainText('1Not yet');
    await expect(page.getByText('All caught up. Next up: 7 cards tomorrow.')).toBeVisible();

    await page.getByRole('link', { name: 'Back to flashcards', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'All caught up' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Practise anyway' })).toBeVisible();
});

test('cards are made with an AI: copy the prompt, paste the reply, keep the new ones', async ({ page }) => {
    await page.setViewportSize(desktop);
    await openDeck(page);
    await page.getByLabel('Topic', { exact: true }).selectOption({ label: 'Joins (3)' });
    await expect(page.getByRole('heading', { name: '3 cards to review today in Joins' })).toBeVisible();
    await page.getByRole('button', { name: 'Make cards with an AI' }).click();
    const maker = page.locator('#card-maker-dialog');
    await expect(maker.getByLabel('About')).toHaveValue(/.+/);
    const prompt = maker.getByLabel('The prompt', { exact: true });
    await expect(prompt).toContainText('10 flashcards about Joins, from the material below.');
    await expect(prompt).toContainText('A left join keeps every row of the left table, matched or not.');
    await expect(prompt).toContainText('- What does a LEFT JOIN keep?');
    await maker.getByLabel('How many').selectOption('5');
    await expect(prompt).toContainText('5 flashcards about Joins');
    await maker.getByLabel('From a note').selectOption({ label: 'Lecture 3: joins' });
    await expect(prompt).toContainText('### The student\'s note: Lecture 3: joins');

    await maker.getByLabel('The AI\'s reply').fill(REPLY);
    await maker.getByRole('button', { name: 'Read the cards' }).click();
    await expect(maker.getByText('3 cards found.')).toBeVisible();
    await expect(maker.getByText('Already a card')).toBeVisible();
    await maker.getByRole('button', { name: 'Add 2 cards' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Added 2 cards.' })).toBeVisible();
    await expect(page.getByRole('heading', { name: '5 cards to review today in Joins' })).toBeVisible();
    await expect(page.locator('.card-row').filter({ hasText: 'What does a CROSS JOIN make?' })).toContainText('Made with an AI');
});

for (const [name, viewport] of Object.entries({ desktop, phone })) {
    test(`every colour of flashcards comes from a token: ${name}`, async ({ page }) => {
        await page.setViewportSize(viewport);
        const student = await openDeck(page);
        await useSentinelTheme(page);
        const states = { deck: await foreignColours(page) };
        await page.getByRole('button', { name: 'New card' }).click();
        await page.locator('#flashcard-dialog').getByLabel('Front').waitFor();
        states.editor = await foreignColours(page);
        await page.keyboard.press('Escape');
        await page.getByRole('button', { name: 'Make cards with an AI' }).click();
        await page.locator('#card-maker-dialog').getByLabel('The prompt', { exact: true }).waitFor();
        states.maker = await foreignColours(page);

        await page.goto(`/workspaces/${student.workspace}/flashcards/review`);
        await page.getByText('Card 1 of 4').waitFor();
        await useSentinelTheme(page);
        states.front = await foreignColours(page);
        await page.getByRole('button', { name: /Show the answer/ }).click();
        await page.getByRole('group', { name: 'How did it go?' }).waitFor();
        states.back = await foreignColours(page);
        for (const [state, colours] of Object.entries(states)) {
            expect(colours, `${state}: colours not from a token`).toEqual([]);
        }
    });
}

for (const theme of THEMES) {
    test(`axe finds no violations in flashcards: ${theme}`, async ({ page }) => {
        await page.setViewportSize(desktop);
        const student = await openDeck(page);
        await useTheme(page, theme);
        expect(await analyse(page), 'deck').toEqual([]);
        await page.getByRole('button', { name: 'New card' }).click();
        await page.locator('#flashcard-dialog').getByLabel('Front').waitFor();
        expect(await analyse(page), 'editor').toEqual([]);
        await page.keyboard.press('Escape');
        await page.getByRole('button', { name: 'Make cards with an AI' }).click();
        await page.locator('#card-maker-dialog').getByLabel('The prompt', { exact: true }).waitFor();
        expect(await analyse(page), 'maker').toEqual([]);

        await page.goto(`/workspaces/${student.workspace}/flashcards/review`);
        await page.getByText('Card 1 of 4').waitFor();
        await useTheme(page, theme);
        expect(await analyse(page), 'front').toEqual([]);
        await page.getByRole('button', { name: /Show the answer/ }).click();
        await page.getByRole('group', { name: 'How did it go?' }).waitFor();
        expect(await analyse(page), 'back').toEqual([]);
        for (let n = 0; n < 4; n++) {
            if (n > 0) await page.getByRole('button', { name: /Show the answer/ }).click();
            await page.getByRole('button', { name: /^Got it/ }).click();
        }
        await page.getByRole('heading', { name: 'Round done' }).waitFor();
        expect(await analyse(page), 'summary').toEqual([]);
    });
}

test('flashcards never scroll sideways at 320 px, even with 200% text', async ({ page }) => {
    const student = await openDeck(page);
    await page.setViewportSize({ width: 320, height: 800 });
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    const fits = async () => expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
    await fits();

    await page.goto(`/workspaces/${student.workspace}/flashcards/review`);
    await page.getByText('Card 1 of 4').waitFor();
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    await fits();
    await page.getByRole('button', { name: /Show the answer/ }).click();
    await page.getByRole('group', { name: 'How did it go?' }).waitFor();
    await fits();
});

test('the phone tab bar fits the six sections at 320 px', async ({ page }) => {
    const student = await openDeck(page);
    await page.setViewportSize({ width: 320, height: 700 });
    await page.goto(`/workspaces/${student.workspace}/flashcards`);
    const bar = page.locator('.app-tabbar');
    await expect(bar.getByRole('link', { name: 'Cards' })).toHaveAttribute('aria-current', 'page');
    expect(await bar.evaluate((el) => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
});

for (const [name, size] of Object.entries({ desktop, phone })) {
    test(`the deck shows the cards by module, and a module opens its cards and its own review: ${name}`, async ({ page }) => {
        await page.setViewportSize(size);
        const student = makeStudentWithModuleCards();
        await openStudentHome(page, student.email);
        await page.goto(`/workspaces/${student.workspace}/flashcards`);
        await page.getByRole('heading', { level: 1, name: 'Flashcards' }).waitFor();
        await page.waitForLoadState('load');

        const modules = page.getByRole('list', { name: 'Cards by module' });
        await expect(modules.locator('.deck-module')).toHaveCount(3);
        await expect(modules.locator('.deck-module').nth(0)).toContainText('Week 1: Relational model');
        await expect(modules.locator('.deck-module').nth(0)).toContainText('5 cards · 4 due');
        await expect(modules.locator('.deck-module').nth(2)).toContainText('No module');
        await expect(page.locator('.card-row')).toHaveCount(0);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
        expect(await analyse(page)).toEqual([]);
        await page.screenshot({ path: `test-results/deck-by-module-${size.width}.png`, fullPage: true });

        // A module's row opens its cards; its Review button starts a round of its own.
        await modules.getByRole('link', { name: 'Week 2: SQL queries' }).click();
        await expect(page.locator('.card-row')).toHaveCount(1);
        await expect(page.getByRole('heading', { name: '1 card to review today in Week 2: SQL queries' })).toBeVisible();
        await expect(page.getByLabel('Module')).toHaveValue(student.week2);
        await page.goto(`/workspaces/${student.workspace}/flashcards`);
        await page.getByRole('list', { name: 'Cards by module' }).locator('.deck-module').nth(1).getByRole('link', { name: 'Review' }).click();
        await expect(page.getByText('Card 1 of 1')).toBeVisible();
        await expect(page.getByRole('region', { name: 'Question' })).toContainText('What does a correlated subquery refer to?');
    });
}

for (const [name, size] of Object.entries({ desktop, phone })) {
    test(`a module page lists its topics and a topic is added there: ${name}`, async ({ page }) => {
        await page.setViewportSize(size);
        const student = makeStudentWithModuleCards();
        await openStudentHome(page, student.email);
        await page.goto(`/workspaces/${student.workspace}/modules/${student.week2}`);
        const topics = page.getByRole('region', { name: /Topics/ });
        await expect(topics).toContainText('Subqueries');
        await expect(topics).toContainText('1 card, 1 due');
        await topics.getByLabel(/Add a topic/).fill('Window functions');
        await topics.getByRole('button', { name: 'Add' }).click();
        await expect(topics.locator('.module-topic')).toHaveCount(2);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
        expect(await analyse(page)).toEqual([]);
        await page.screenshot({ path: `test-results/module-topics-${size.width}.png` });
    });
}
