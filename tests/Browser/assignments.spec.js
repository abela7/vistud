import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { fileURLToPath } from 'node:url';
import { makeStudentWithModules, openStudentHome } from './support.js';

/* Assignments: a name, a deadline and its files (the owner's review, 2026-10-02). */

const fixture = (name) => fileURLToPath(new URL(`./fixtures/files/${name}`, import.meta.url));
const devices = {
    computer: { viewport: { width: 1440, height: 900 } },
    phone: { viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true },
};

test.use({ reducedMotion: 'reduce' });

/** A section's ⋯ menu. */
async function planMenuOf(page, section, item) {
    await page.getByRole('button', { name: `Actions for ${section}` }).click();
    await page.locator('.row-menu:popover-open').getByRole(item === 'Open its folder' ? 'link' : 'button', { name: item }).click();
}

/** The plan's ⋯ menu: Add a pre-made plan, Clear the plan. */
async function planMenu(page, item) {
    await page.getByRole('button', { name: 'Actions for the plan' }).click();
    await page.locator('.row-menu:popover-open').getByRole('button', { name: item }).click();
}

/** Biology's Assignments section, reached the way a student would. */
async function openAssignments(page, onPhone) {
    await openStudentHome(page, makeStudentWithModules());
    await page.locator('main').getByRole('link', { name: 'Biology' }).click();
    await page.getByRole('heading', { level: 1, name: 'Biology' }).waitFor();
    const nav = onPhone ? page.locator('.app-tabbar') : page.locator('.app-sidebar');
    await nav.getByRole('link', { name: onPhone ? 'Tasks' : 'Assignments' }).click();
    await page.getByRole('heading', { level: 1, name: 'Assignments' }).waitFor();
}

for (const [name, device] of Object.entries(devices)) {
    test.describe(name, () => {
        test.use(device);

        test(`an assignment is made with its name, deadline and files, and tracked (${name})`, async ({ page }) => {
            const onPhone = name === 'phone';
            await openAssignments(page, onPhone);
            await expect(page.getByText('No assignments yet')).toBeVisible();
            await page.locator('main').getByRole('link', { name: 'New assignment' }).first().click();
            await page.getByRole('heading', { level: 1, name: 'New assignment' }).waitFor();

            await page.getByLabel('Name').fill('Coursework 1: cell report');
            await page.getByLabel('Module').selectOption({ label: 'Week 1: Cells' });
            await page.getByLabel('Day').fill('2030-03-14');
            await page.getByLabel('Time (optional)').fill('23:59');
            // Files chosen before it exists wait, and go up once it is created.
            await page.locator('input[data-upload-files]').setInputFiles([fixture('Lecture 2 - cell division.pdf'), fixture('Onion cells.png')]);
            await expect(page.getByRole('list', { name: 'Chosen files' }).getByText('Ready to add')).toHaveCount(2);
            await page.getByRole('button', { name: 'Create' }).click();

            await expect(page).toHaveURL(/\/assignments\/[0-9a-f-]+$/);
            await expect(page.getByRole('heading', { level: 1, name: 'Coursework 1: cell report' })).toBeVisible();
            await expect(page.getByText('2 files added.')).toBeVisible();
            const files = page.locator('.assignment-files');
            await expect(files.getByRole('link', { name: 'Lecture 2 - cell division.pdf' })).toBeVisible();
            await expect(files.getByRole('link', { name: 'Onion cells.png' })).toBeVisible();
            await expect(page.locator('.assignment-deadline')).toContainText('Due 14 Mar 2030, 23:59');

            // Where the student is with it.
            await page.getByRole('group', { name: 'Where you are' }).getByRole('button', { name: 'In progress' }).click();
            await expect(page.getByText('In progress.', { exact: true })).toBeVisible();

            // Nothing on the page scrolls sideways.
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1)).toBe(true);

            // On the list: its card, with its files; its folder is in its module with them.
            await page.getByRole('link', { name: 'Back to Assignments' }).click();
            const card = page.locator('.question-card').filter({ hasText: 'Coursework 1: cell report' });
            await expect(card).toContainText('2 files');
            await expect(card).toContainText('In progress');
            await card.getByRole('link', { name: 'Coursework 1: cell report' }).click();
            await page.getByRole('link', { name: 'Open its folder' }).click();
            await expect(page.getByRole('heading', { level: 1, name: 'Coursework 1: cell report' })).toBeVisible();
            await expect(page.locator('main').getByRole('link', { name: 'Onion cells.png' })).toBeVisible();
        });
    });
}


for (const [name, device] of Object.entries(devices)) {
    test.describe(`plan on a ${name}`, () => {
        test.use(device);

        test(`an assignment's plan is made, ticked, checked and changed (${name})`, async ({ page }) => {
            const onPhone = name === 'phone';
            await openAssignments(page, onPhone);
            await page.locator('main').getByRole('link', { name: 'New assignment' }).first().click();
            await page.getByLabel('Name').fill('Coursework 2: osmosis report');
            await page.getByLabel('Day').fill('2030-03-14');
            await page.getByRole('button', { name: 'Create' }).click();
            await page.getByRole('heading', { level: 1, name: 'Coursework 2: osmosis report' }).waitFor();

            // An empty plan is calm: a new section is one form away, and the pre-made plans wait in the menu.
            await expect(page.getByText('Split the work into sections')).toBeVisible();
            await expect(page.getByRole('button', { name: /Essay or report/ })).toHaveCount(0);
            await planMenu(page, 'Add a pre-made plan');
            await page.getByRole('button', { name: /Essay or report/ }).click();
            await expect(page.getByRole('heading', { level: 3, name: 'Research' })).toBeVisible();
            await expect(page.locator('.plan-percent')).toHaveText('0%');
            await expect(page.getByRole('group', { name: /Use of sources/ })).toBeVisible();

            // Ticking the first step starts the assignment and moves the bar.
            await page.getByRole('button', { name: 'Done: Find and read the sources' }).click();
            await expect(page.getByText('1 of 12 done')).toBeVisible();
            await expect(page.getByRole('group', { name: 'Where you are' }).getByRole('button', { name: 'In progress' })).toHaveAttribute('aria-pressed', 'true');
            await expect(page.locator('.plan-pace')).toContainText('left');

            // A step added to a part, a criterion checked, a step renamed.
            await page.locator('section[aria-label="Part: Research"]').getByRole('button', { name: /Add a task/i }).click();
            await page.getByLabel('Add a task to Research').fill('Check the library catalogue');
            await page.getByLabel('Add a task to Research').press('Enter');
            await expect(page.getByText('Check the library catalogue', { exact: true })).toBeVisible();
            await expect(page.getByText('1 of 13 done')).toBeVisible();
            await page.getByRole('group', { name: /Use of sources/ }).getByRole('button', { name: 'Partly' }).click();
            await expect(page.getByRole('group', { name: /Use of sources/ }).getByRole('button', { name: 'Partly' })).toHaveAttribute('aria-pressed', 'true');
            await page.getByRole('button', { name: 'Actions for Note the key points' }).click();
            await page.locator('.row-menu:popover-open').getByRole('button', { name: 'Edit' }).click();
            await page.locator('#plan-edit-title').fill('Note the key points and quotes');
            await page.locator('#plan-edit-title').press('Enter');
            await expect(page.getByText('Note the key points and quotes', { exact: true })).toBeVisible();

            // It is all still there after a reload, and nothing scrolls sideways.
            await page.reload();
            await expect(page.getByText('1 of 13 done')).toBeVisible();
            await expect(page.getByRole('group', { name: /Use of sources/ }).getByRole('button', { name: 'Partly' })).toHaveAttribute('aria-pressed', 'true');
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1)).toBe(true);

            // With an AI: the brief goes into the prompt, the reply is read, looked over and added.
            await page.getByRole('button', { name: 'Plan with an AI' }).click();
            await page.getByLabel(/The assignment brief/).fill('Explain osmosis in 1500 words. Marked on accuracy (50%) and clarity (50%).');
            await expect(page.getByLabel('The prompt', { exact: true })).toContainText('Explain osmosis in 1500 words.');
            await page.getByLabel("The AI's reply").fill('Here you go:\n<part title="Lab write-up" marks="50"><step>Write the method</step></part><criterion marks="50">Accuracy</criterion>');
            await page.getByRole('button', { name: 'Read the reply' }).click();
            await expect(page.getByText('Look it over, then add it')).toBeVisible();
            await page.getByRole('button', { name: 'Add to my plan' }).click();
            await expect(page.getByRole('heading', { level: 3, name: 'Lab write-up' })).toBeVisible();
            await expect(page.getByText('3 things added to your plan.')).toBeVisible();

            // Its progress is on the card in the list.
            await page.getByRole('link', { name: 'Back to Assignments' }).click();
            const card = page.locator('.question-card').filter({ hasText: 'Coursework 2: osmosis report' });
            await expect(card).toContainText('1 of 14 done');
            await expect(card).toContainText('In progress');

            // A plan that isn't wanted is cleared, after asking.
            await card.getByRole('link', { name: 'Coursework 2: osmosis report' }).click();
            await page.getByRole('heading', { level: 1, name: 'Coursework 2: osmosis report' }).waitFor();
            await planMenu(page, 'Clear the plan');
            await expect(page.getByText('Remove everything in the plan?')).toBeVisible();
            await page.getByRole('button', { name: 'Keep it', exact: true }).click();
            await expect(page.getByRole('heading', { level: 3, name: 'Research' })).toBeVisible();
            await planMenu(page, 'Clear the plan');
            await page.getByRole('button', { name: 'Remove it all' }).click();
            await expect(page.getByText('The plan is cleared.')).toBeVisible();
            await expect(page.getByText('Split the work into sections')).toBeVisible();

            // Built by hand: sections with their weights, checked to add up to 100, and a weight changed in place.
            await page.getByLabel('Name of the section').fill('Method');
            await page.getByLabel('Weight, out of 100 (optional)').fill('40');
            await page.getByLabel('Name of the section').press('Enter');
            await expect(page.locator('.plan-facts')).toContainText('Weights add up to 40%: 60% is in no section');
            await page.getByLabel('Name of the section').fill('Results');
            await page.getByLabel('Weight, out of 100 (optional)').fill('50');
            await page.getByLabel('Name of the section').press('Enter');
            await page.getByRole('button', { name: /Weight of Results: 50%/ }).click();
            await page.getByLabel('Weight of Results, out of 100 (empty for none)').fill('60');
            await page.getByLabel('Weight of Results, out of 100 (empty for none)').press('Enter');
            await expect(page.locator('.plan-facts')).toContainText('Weights add up to 100%');
            await page.getByRole('button', { name: 'Done: Method' }).click();
            await expect(page.locator('.plan-percent')).toHaveText('40%');
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1)).toBe(true);

            // A section keeps its own things: files go up into its folder, and a folder is made in it.
            const results = page.locator('section[aria-label="Part: Results"]');
            await results.locator('input[type=file]').setInputFiles(fixture('Onion cells.png'));
            await expect(page.getByText('1 file added.')).toBeVisible();
            await expect(results.getByRole('link', { name: 'Onion cells.png' })).toBeVisible();
            await results.getByRole('button', { name: /New folder/i }).click();
            await page.getByLabel('Name of the new folder in Results').fill('Graphs');
            await page.getByLabel('Name of the new folder in Results').press('Enter');
            await expect(results.getByRole('link', { name: 'Graphs' })).toBeVisible();

            // As a list: one line a section, opened to show what is in it; the choice is remembered.
            await page.getByRole('button', { name: 'List', exact: true }).click();
            await expect(results.getByRole('link', { name: 'Graphs' })).toBeHidden();
            await expect(results.locator('.plan-section-summary')).toContainText('2 items');
            await results.getByRole('button', { name: 'What is in Results' }).click();
            await expect(results.getByRole('link', { name: 'Graphs' })).toBeVisible();
            await page.reload();
            await expect(page.getByRole('button', { name: 'List', exact: true })).toHaveAttribute('aria-pressed', 'true');
            await expect(page.locator('section[aria-label="Part: Results"]').getByRole('link', { name: 'Graphs' })).toBeHidden();
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1)).toBe(true);
            await page.getByRole('button', { name: 'Cards', exact: true }).click();
            await expect(page.locator('section[aria-label="Part: Results"]').getByRole('link', { name: 'Graphs' })).toBeVisible();

            // Its folder is in the assignment's folder, in the module, with the file in it.
            await planMenuOf(page, 'Results', 'Open its folder');
            await expect(page.getByRole('heading', { level: 1, name: 'Results' })).toBeVisible();
            await expect(page.locator('main').getByRole('link', { name: 'Onion cells.png' })).toBeVisible();
        });
    });
}

test('axe finds no violations on the assignment pages', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await openAssignments(page, false);
    await page.locator('main').getByRole('link', { name: 'New assignment' }).first().click();
    await page.getByRole('heading', { level: 1, name: 'New assignment' }).waitFor();
    const analyse = async () => (await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze())
        .violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target).join(' ')}`);
    expect(await analyse()).toEqual([]);
    await page.getByLabel('Name').fill('Lab report 3');
    await page.getByLabel('Day').fill('2030-03-14');
    await page.getByRole('button', { name: 'Create' }).click();
    await page.getByRole('heading', { level: 1, name: 'Lab report 3' }).waitFor();
    expect(await analyse()).toEqual([]);
    await page.getByRole('button', { name: 'Edit details' }).click();
    await page.getByLabel('Name', { exact: true }).waitFor();
    expect(await analyse()).toEqual([]);
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    await planMenu(page, 'Add a pre-made plan');
    expect(await analyse()).toEqual([]);
    await page.getByRole('button', { name: /Lab report/ }).click();
    await page.getByRole('heading', { level: 3, name: 'Data' }).waitFor();
    expect(await analyse()).toEqual([]);
    await page.getByRole('button', { name: 'Plan with an AI' }).click();
    await page.getByText('See the prompt').click();
    await page.getByLabel('The prompt', { exact: true }).waitFor();
    expect(await analyse()).toEqual([]);
    await page.getByRole('link', { name: 'Back to Assignments' }).click();
    await page.getByRole('heading', { level: 1, name: 'Assignments' }).waitFor();
    expect(await analyse()).toEqual([]);
});

test('the plan never scrolls sideways at 320 px, even with 200% text', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 640 });
    await openAssignments(page, true);
    await page.locator('main').getByRole('link', { name: 'New assignment' }).first().click();
    await page.getByLabel('Name').fill('Lab report with a rather long name that goes on and on');
    await page.getByLabel('Day').fill('2030-03-14');
    await page.getByRole('button', { name: 'Create' }).click();
    await page.getByRole('heading', { level: 1, name: /Lab report with a rather long name/ }).waitFor();
    await planMenu(page, 'Add a pre-made plan');
    await page.getByRole('button', { name: /Essay or report/ }).click();
    await page.getByRole('heading', { level: 3, name: 'Research' }).waitFor();
    await page.getByRole('button', { name: 'Plan with an AI' }).click();
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
});

/* Projects, including group work: states, details, steps under steps, milestones and a team (the owner's review, 2026-10-03). */

async function newProject(page, name) {
    await page.locator('main').getByRole('link', { name: 'New assignment' }).first().click();
    await page.getByLabel('Name').fill(name);
    await page.getByLabel('Kind').selectOption({ label: 'Project' });
    await page.getByLabel('Day').fill('2030-03-14');
    await page.getByRole('button', { name: 'Create' }).click();
    await page.getByRole('heading', { level: 1, name }).waitFor();
}

for (const [name, device] of Object.entries(devices)) {
    test.describe(`project on a ${name}`, () => {
        test.use(device);

        test(`a group project is planned, shared out and watched (${name})`, async ({ page }) => {
            const onPhone = name === 'phone';
            await openAssignments(page, onPhone);
            await newProject(page, 'Group project: library app');

            // A project has its own pre-made plans, first in the list, when they are asked for.
            await planMenu(page, 'Add a pre-made plan');
            await page.getByRole('button', { name: /Group project/ }).first().click();
            await expect(page.getByRole('heading', { level: 3, name: 'Team set-up' })).toBeVisible();
            await expect(page.getByRole('heading', { level: 3, name: /Milestones/ })).toBeVisible();
            await expect(page.getByText('Roles agreed', { exact: true })).toBeVisible();

            // The team is added when wanted: the student is one of them.
            await page.getByRole('button', { name: 'Team', exact: true }).click();
            await page.getByRole('button', { name: 'Add a person' }).click();
            await page.getByLabel('Add a person').fill('Abel');
            await page.getByText('This is me').click();
            await page.getByLabel('Add a person').press('Enter');
            await expect(page.locator('.plan-member').filter({ hasText: 'Abel' })).toContainText('You');
            await page.getByLabel('Add a person').fill('Sara Bekele');
            await page.getByLabel('Add a person').press('Enter');
            await expect(page.locator('.plan-member').filter({ hasText: 'Sara Bekele' })).toContainText('Nothing yet');

            // A step is given a person, dates and a priority in its details, and says so.
            await page.getByRole('button', { name: 'Actions for Agree who does what' }).click();
            await page.locator('.row-menu:popover-open').getByRole('button', { name: 'Edit details' }).click();
            await page.locator('#plan-edit-member').selectOption({ label: 'Sara Bekele' });
            await page.locator('#plan-edit-start').fill('2030-03-01');
            await page.locator('#plan-edit-due').fill('2030-03-05');
            await page.locator('#plan-edit-priority').selectOption('high');
            await page.locator('#plan-edit-labels').fill('team, planning');
            await page.locator('#plan-edit-title').press('Enter');
            const row = page.locator('.plan-step').filter({ hasText: 'Agree who does what' });
            await expect(row).toContainText('Sara Bekele');
            await expect(row).toContainText('1 Mar 2030 – 5 Mar 2030');
            await expect(row).toContainText('High');
            await expect(row).toContainText('planning');
            await expect(page.locator('.plan-member').filter({ hasText: 'Sara Bekele' })).toContainText('0 of 1 done');

            // Stuck: the step says so, and the work is at risk, with the reason.
            await page.getByRole('button', { name: 'Actions for Agree who does what' }).click();
            await page.locator('.row-menu:popover-open').getByRole('button', { name: 'I am stuck' }).click();
            await expect(row).toContainText('Stuck');
            await expect(page.locator('.plan-progress')).toContainText('At risk');
            await expect(page.locator('.plan-progress')).toContainText('1 task is stuck');

            // A step under a step, and done.
            await page.getByRole('button', { name: 'Actions for Agree who does what' }).click();
            await page.locator('.row-menu:popover-open').getByRole('button', { name: 'Add a task under it' }).click();
            await page.getByLabel('Add a task under Agree who does what').fill('Write the roles down');
            await page.getByLabel('Add a task under Agree who does what').press('Enter');
            await expect(page.getByText('Write the roles down', { exact: true })).toBeVisible();
            await page.getByRole('button', { name: 'Done adding' }).click();
            await page.getByRole('button', { name: 'Done: Write the roles down' }).click();
            await expect(page.locator('.plan-progress')).not.toContainText('At risk');

            // A milestone is reached.
            await page.getByRole('button', { name: 'Reached: Roles agreed' }).click();
            await expect(page.getByRole('button', { name: 'Reached: Roles agreed' })).toHaveAttribute('aria-pressed', 'true');

            // After a reload it is all still there; the card and the page agree; nothing scrolls sideways.
            await page.reload();
            await expect(page.locator('.plan-member').filter({ hasText: 'Sara Bekele' })).toContainText('1 of 1 done');
            await expect(page.getByText('Write the roles down', { exact: true })).toBeVisible();
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1)).toBe(true);
            await page.getByRole('link', { name: 'Back to Assignments' }).click();
            await expect(page.locator('.question-card').filter({ hasText: 'Group project: library app' })).toContainText('Project');
        });
    });
}

test('axe finds no violations on a project plan with its editor, milestones and team', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await openAssignments(page, false);
    await newProject(page, 'Project: axe');
    await planMenu(page, 'Add a pre-made plan');
    await page.getByRole('button', { name: /^Project/ }).first().click();
    await page.getByRole('heading', { level: 3, name: 'Initiate' }).waitFor();
    await page.getByRole('button', { name: 'Team', exact: true }).click();
    await page.getByRole('button', { name: 'Add a person' }).click();
    await page.getByLabel('Add a person').fill('Sara Bekele');
    await page.getByLabel('Add a person').press('Enter');
    await page.getByRole('button', { name: 'Actions for Sara Bekele' }).waitFor();
    await page.getByRole('button', { name: 'Actions for Define the goal and the scope' }).click();
    await page.locator('.row-menu:popover-open').getByRole('button', { name: 'Edit details' }).click();
    await page.locator('#plan-edit-title').waitFor();
    const analyse = async () => (await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze())
        .violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target).join(' ')}`);
    expect(await analyse()).toEqual([]);
    await page.locator('#plan-edit-title').press('Escape');
    await page.getByRole('button', { name: 'Actions for Define the goal and the scope' }).click();
    await page.locator('.row-menu:popover-open').getByRole('button', { name: 'I am stuck' }).click();
    await expect(page.locator('.plan-progress')).toContainText('At risk');
    expect(await analyse()).toEqual([]);
});

test('a project plan never scrolls sideways at 320 px, even with 200% text and the editor open', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 640 });
    await openAssignments(page, true);
    await newProject(page, 'A project with a rather long name that goes on and on');
    await planMenu(page, 'Add a pre-made plan');
    await page.getByRole('button', { name: /Group project/ }).first().click();
    await page.getByRole('heading', { level: 3, name: 'Team set-up' }).waitFor();
    await page.getByRole('button', { name: 'Team', exact: true }).click();
    await page.getByRole('button', { name: 'Add a person' }).click();
    await page.getByLabel('Add a person').fill('Sara Bekele with a very long name indeed');
    await page.getByLabel('Add a person').press('Enter');
    await page.getByRole('button', { name: 'Actions for Agree who does what' }).click();
    await page.locator('.row-menu:popover-open').getByRole('button', { name: 'Edit details' }).click();
    await page.locator('#plan-edit-title').waitFor();
    await page.locator('#plan-edit-due').fill('2030-03-05');
    await page.locator('#plan-edit-labels').fill('a-rather-long-label, another-long-label');
    await page.locator('#plan-edit-title').press('Enter');
    await page.getByRole('button', { name: 'Actions for Agree who does what' }).click();
    await page.locator('.row-menu:popover-open').getByRole('button', { name: 'I am stuck' }).click();
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
});
