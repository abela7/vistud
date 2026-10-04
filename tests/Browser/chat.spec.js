import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { spawn, execFileSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { foreignColours, makeStudentAccount, makeStudentWithCards, makeStudentWithModulePage, makeStudentWithNote, makeStudentWithTopics, openStudentHome, THEMES, turnOnAi, useSentinelTheme, useTheme } from './support.js';
import { makeStudentWithSession } from './support.js';

/*
 * The tutor's chat on a session page, against a fake service that streams its answer slowly
 * (tests/Browser/fixtures/fake-engine.php): the student's words show at once, the answer arrives in
 * pieces, and the finished turn takes its place (docs/specs/study-memory.md §6).
 */

const appRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const php = process.env.PHP_BINARY || 'php';
const port = 8099;
const tinker = (code) => execFileSync(php, ['artisan', 'tinker', '--execute', code], { cwd: appRoot, stdio: 'pipe' }).toString().trim().split('\n').pop();
let engine;
let before;

test.describe.configure({ mode: 'serial', timeout: 60_000 });

test.beforeAll(async () => {
    engine = spawn(php, ['-S', `127.0.0.1:${port}`, resolve(appRoot, 'tests/Browser/fixtures/fake-engine.php')], { stdio: 'ignore' });
    await new Promise((done) => setTimeout(done, 500));
    // The installation's service address points at the fake for these tests, and is put back after.
    before = tinker(`echo json_encode(DB::table('platform_settings')->where('key', 'engine.url')->value('value'));`);
    tinker(`DB::table('platform_settings')->upsert([['key' => 'engine.url', 'value' => 'http://127.0.0.1:${port}/api/v1', 'updated_at' => now()]], ['key'], ['value', 'updated_at']); Cache::forget('vistud.engine.models');`);
});

test.afterAll(async () => {
    const value = JSON.parse(before);
    tinker(value === null
        ? `DB::table('platform_settings')->where('key', 'engine.url')->delete(); Cache::forget('vistud.engine.models');`
        : `DB::table('platform_settings')->where('key', 'engine.url')->update(['value' => ${JSON.stringify(value)}]); Cache::forget('vistud.engine.models');`);
    engine?.kill();
});

test('the answer streams in as it is written, then stays as a turn', async ({ page }) => {
    const student = makeStudentWithSession();
    tinker([
        `$p = app(\\App\\Identity\\PrincipalFactory::class)->forUser(\\App\\Models\\User::query()->where('email', '${student.email}')->firstOrFail(), 'web');`,
        `app(\\App\\Engine\\Settings::class)->setKey($p, 'sk-or-browser-test-0000000000');`,
        `app(\\App\\Engine\\Settings::class)->set($p, ['tutor_model' => 'fake/tutor', 'consent' => true]);`,
    ].join(' '));
    await openStudentHome(page, student.email);
    await page.goto(`/courses/${student.workspace}/sessions/${student.session}`);
    await page.getByRole('heading', { name: 'Your tutor' }).waitFor();
    await page.waitForLoadState('load');

    const box = page.getByLabel('Write to your tutor');
    await box.fill('What is a left join?');
    await box.press('Enter');

    // The student's words at once, the box emptied; then the answer in pieces before it is whole.
    const chat = page.locator('.chat');
    await expect(chat.locator('.chat-turn.is-me').last()).toHaveText('What is a left join?');
    await expect(box).toHaveValue('');
    const live = chat.locator('.chat-live');
    await expect(live).toContainText('A left join', { timeout: 10_000 });
    await expect(live).not.toContainText('table.');

    // Finished: one turn each, the answer rendered, nothing left in the live area.
    const answer = chat.locator('li[wire\\:key^="turn-"].is-tutor .chat-markdown');
    await expect(answer).toHaveCount(1, { timeout: 15_000 });
    await expect(answer).toContainText('A left join keeps every row of the left table.');
    await expect(answer.locator('strong')).toHaveText('left');
    await expect(chat.locator('.chat-turn.is-me:visible')).toHaveCount(1);
    await expect(live).toBeHidden();
    await expect(box).toBeEnabled();
    await expect(box).toBeFocused();

    // A second turn with a look-up: the chat says what it is reading, then answers.
    await box.fill('What do my notes say?');
    await box.press('Enter');
    await expect(chat.locator('.chat-status')).toHaveText('Looking up your notes…', { timeout: 10_000 });
    await expect(answer).toHaveCount(2, { timeout: 15_000 });
    await expect(answer.last()).toHaveText('Your notes cover joins.');
    await expect(chat.locator('li[data-turn] .chat-meta').last()).toContainText('Looked up: your notes');
    await expect(chat.locator('.chat-turn.is-me:visible')).toHaveCount(2);

    // A busy service: the words stay in the chat, and Try again answers them once.
    await box.fill('Are you busy?');
    await box.press('Enter');
    await expect(chat.getByRole('alert')).toContainText('busy right now', { timeout: 10_000 });
    await expect(chat.locator('.chat-turn.is-me:visible')).toHaveCount(3);
    await chat.getByRole('button', { name: 'Try again' }).click();
    await expect(answer).toHaveCount(3, { timeout: 15_000 });
    await expect(answer.last()).toHaveText('Back now: joins combine rows.');
    await expect(chat.getByRole('button', { name: 'Try again' })).toHaveCount(0);
    await expect(chat.getByRole('alert')).toHaveCount(0);
    await expect(chat.locator('.chat-turn.is-me:visible')).toHaveCount(3);

    // A chat with turns in it passes axe.
    const violations = (await new AxeBuilder({ page }).include('.chat').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze())
        .violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target).join(' ')}`);
    expect(violations).toEqual([]);
});

test('notes and pictures go with a message: picked, uploaded or pasted', async ({ page }) => {
    const student = makeStudentWithSession();
    tinker([
        `$p = app(\\App\\Identity\\PrincipalFactory::class)->forUser(\\App\\Models\\User::query()->where('email', '${student.email}')->firstOrFail(), 'web');`,
        `app(\\App\\Engine\\Settings::class)->setKey($p, 'sk-or-browser-test-0000000000');`,
        `app(\\App\\Engine\\Settings::class)->set($p, ['tutor_model' => 'fake/tutor', 'consent' => true]);`,
    ].join(' '));
    await openStudentHome(page, student.email);
    await page.goto(`/courses/${student.workspace}/sessions/${student.session}`);
    await page.getByRole('heading', { name: 'Your tutor' }).waitFor();
    await page.waitForLoadState('load');
    const chat = page.locator('.chat');
    const box = chat.getByLabel('Write to your tutor');
    const answers = chat.locator('li[wire\\:key^="turn-"].is-tutor .chat-markdown');

    // Pick the module's note: a chip, then on the message, then the tutor has it.
    await chat.getByRole('button', { name: /^More: attach/ }).click();
    await chat.getByRole('button', { name: 'Attach a note, file or picture' }).click();
    const picker = chat.getByRole('group', { name: 'Attach a note, a file or a picture' });
    await picker.getByLabel('Find a note or a file').fill('lecture');
    await picker.getByRole('button', { name: /Lecture 3: joins/ }).click();
    await expect(picker.getByRole('button', { name: /Lecture 3: joins/ })).toHaveAttribute('aria-pressed', 'true');
    await page.keyboard.press('Escape');
    await expect(picker).toBeHidden();
    await expect(chat.getByRole('list', { name: 'Attached to your message' })).toContainText('Lecture 3: joins');
    await box.fill('Explain my note');
    await box.press('Enter');
    await expect(answers).toHaveCount(1, { timeout: 15_000 });
    await expect(answers.last()).toHaveText('I have your note.');
    await expect(chat.locator('li[data-turn].is-me').getByRole('link', { name: 'Lecture 3: joins' })).toBeVisible();
    await expect(chat.getByRole('list', { name: 'Attached to your message' })).toBeHidden();

    // Upload a picture from the picker: it goes into the module, then to the tutor.
    await chat.getByRole('button', { name: /^More: attach/ }).click();
    await chat.getByRole('button', { name: 'Attach a note, file or picture' }).click();
    await picker.locator('input[type=file]').setInputFiles(resolve(appRoot, 'tests/Browser/fixtures/files/Onion cells.png'));
    await expect(chat.getByRole('list', { name: 'Attached to your message' })).toContainText('Onion cells.png', { timeout: 10_000 });
    await box.press('Enter');
    await expect(chat.getByRole('alert')).toHaveCount(0);
    await expect(answers).toHaveCount(2, { timeout: 15_000 });
    await expect(answers.last()).toHaveText('I can see the picture.');

    // Paste a screenshot into the box; take it off again before sending.
    await box.evaluate((el) => {
        const canvas = document.createElement('canvas');
        [canvas.width, canvas.height] = [4, 3];
        return new Promise((done) => canvas.toBlob((blob) => {
            const data = new DataTransfer();
            data.items.add(new File([blob], 'image.png', { type: 'image/png' }));
            el.dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true }));
            done();
        }, 'image/png'));
    });
    const pasted = chat.getByRole('list', { name: 'Attached to your message' }).locator('.chat-file', { hasText: 'Pasted picture' });
    await expect(pasted).toBeVisible({ timeout: 10_000 });
    await pasted.getByRole('button', { name: /^Take off Pasted picture/ }).click();
    await expect(chat.getByRole('list', { name: 'Attached to your message' })).toBeHidden();

    // The uploads landed in the module's "From the chat" folder.
    const kept = tinker(`echo json_encode(DB::table('files')->join('folders', 'folders.id', '=', 'files.folder_id')->where('files.workspace_id', '${student.workspace}')->orderBy('files.name')->get(['files.name', 'files.extension', 'folders.name as folder'])->map(fn ($r) => $r->folder.'/'.$r->name.'.'.$r->extension)->all());`);
    expect(JSON.parse(kept).map((path) => path.replace(/ \d{4}-\d{2}-\d{2} \d{2}\.\d{2}/, ''))).toEqual(['From the chat/Onion cells.png', 'From the chat/Pasted picture.png']);

    const violations = (await new AxeBuilder({ page }).include('.chat').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze())
        .violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target).join(' ')}`);
    expect(violations).toEqual([]);
});

test('a diagram and a formula in a reply are drawn, with the diagram as text under it', async ({ page }) => {
    const student = makeStudentWithSession();
    tinker([
        `$p = app(\\App\\Identity\\PrincipalFactory::class)->forUser(\\App\\Models\\User::query()->where('email', '${student.email}')->firstOrFail(), 'web');`,
        `app(\\App\\Engine\\Settings::class)->setKey($p, 'sk-or-browser-test-0000000000');`,
        `app(\\App\\Engine\\Settings::class)->set($p, ['tutor_model' => 'fake/tutor', 'consent' => true]);`,
    ].join(' '));
    await openStudentHome(page, student.email);
    await page.goto(`/courses/${student.workspace}/sessions/${student.session}`);
    await page.getByRole('heading', { name: 'Your tutor' }).waitFor();
    await page.waitForLoadState('load');
    const chat = page.locator('.chat');
    const box = chat.getByLabel('Write to your tutor');
    await box.fill('Please draw the process states');
    await box.press('Enter');

    const reply = chat.locator('li[data-turn].is-tutor .chat-markdown').last();
    const diagram = reply.locator('figure.chat-diagram');
    await expect(diagram.locator('svg')).toBeVisible({ timeout: 20_000 });
    await expect(diagram.locator('svg')).toContainText('Running');
    await expect(reply.locator('code.language-mermaid')).toHaveCount(0);
    await diagram.getByText('The diagram as text').click();
    await expect(diagram.locator('details pre')).toContainText('A[New] --> B[Ready]');
    await expect(reply.locator('.katex').first()).toBeVisible();

    // Another turn leaves the drawn reply as it is, and a theme change draws it again.
    await box.fill('Thanks');
    await box.press('Enter');
    await expect(chat.locator('li[data-turn].is-tutor')).toHaveCount(2, { timeout: 15_000 });
    await expect(chat.locator('li[data-turn].is-tutor').first().locator('figure.chat-diagram svg')).toBeVisible();
    await page.evaluate(() => window.dispatchEvent(new CustomEvent('vistud:theme-changed')));
    await expect(chat.locator('figure.chat-diagram svg')).toHaveCount(1);

    // Quiz me: the menu offers the session's topic, its module and what's hardest; a choice is sent as a message.
    await chat.getByRole('button', { name: 'Quiz me' }).click();
    const quiz = chat.getByRole('group', { name: 'Quiz me' });
    await expect(quiz.getByRole('button')).toHaveText(['On Joins', 'On Week 1: Relational model', 'On what I find hardest', 'Test me on the module']);
    await quiz.getByRole('button', { name: 'On Joins' }).click();
    await expect(quiz).toBeHidden();
    await expect(chat.locator('li[data-turn].is-me').last()).toHaveText('Quiz me on Joins.', { timeout: 15_000 });
    await expect(chat.locator('li[data-turn].is-tutor')).toHaveCount(3, { timeout: 15_000 });

    // On a phone the diagram scrolls in its own box; the page never scrolls sideways.
    await page.setViewportSize({ width: 320, height: 700 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);
    await page.setViewportSize({ width: 1280, height: 800 });

    const violations = (await new AxeBuilder({ page }).include('.chat').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze())
        .violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target).join(' ')}`);
    expect(violations).toEqual([]);
});

test('the tutor saves cards and writes in a note that updates while it is open', async ({ page, context }) => {
    const student = makeStudentWithSession();
    tinker([
        `$p = app(\\App\\Identity\\PrincipalFactory::class)->forUser(\\App\\Models\\User::query()->where('email', '${student.email}')->firstOrFail(), 'web');`,
        `app(\\App\\Engine\\Settings::class)->setKey($p, 'sk-or-browser-test-0000000000');`,
        `app(\\App\\Engine\\Settings::class)->set($p, ['tutor_model' => 'fake/tutor', 'consent' => true]);`,
    ].join(' '));
    await openStudentHome(page, student.email);
    await page.goto(`/courses/${student.workspace}/sessions/${student.session}`);
    await page.getByRole('heading', { name: 'Your tutor' }).waitFor();
    await page.waitForLoadState('load');
    const chat = page.locator('.chat');
    const box = chat.getByLabel('Write to your tutor');
    const done = chat.getByRole('list', { name: 'Done in your course' });

    // Cards go straight into the deck, and the answer says so.
    await box.fill('Please make cards from the first slides');
    await box.press('Enter');
    await expect(done.last()).toContainText('Saved 2 flashcards', { timeout: 15_000 });
    const cards = JSON.parse(tinker(`echo json_encode(DB::table('flashcards')->where('workspace_id', '${student.workspace}')->orderBy('front')->pluck('front')->all());`));
    expect(cards).toEqual(['What is a kernel?', 'What is an OS?']);

    // The first note: the session's study note, linked from the answer.
    await box.fill('Please jot this down');
    await box.press('Enter');
    const link = done.last().getByRole('link', { name: /^Wrote in Study notes · Joins/ });
    await expect(link).toBeVisible({ timeout: 15_000 });
    await expect(link).toHaveAttribute('data-note-window', '');

    // Open beside the chat, the note shows what the tutor wrote, and the next write appears without a reload.
    const note = await context.newPage();
    await note.goto(await link.getAttribute('href'));
    const editor = note.locator('.ProseMirror');
    await expect(editor).toContainText('The kernel is the core of the OS.', { timeout: 15_000 });
    await box.fill('Please jot more about system calls');
    await box.press('Enter');
    await expect(editor).toContainText('System calls ask the kernel for help.', { timeout: 15_000 });
    await expect(note.locator('[data-save-status]')).not.toHaveAttribute('data-state', 'conflict');
    await note.close();
});


/** A student with a session, the AI set up against the fake service. */
async function openReadySession(page, size = { width: 1440, height: 900 }) {
    const student = makeStudentWithSession();
    tinker([
        `$p = app(\\App\\Identity\\PrincipalFactory::class)->forUser(\\App\\Models\\User::query()->where('email', '${student.email}')->firstOrFail(), 'web');`,
        `app(\\App\\Engine\\Settings::class)->setKey($p, 'sk-or-browser-test-0000000000');`,
        `app(\\App\\Engine\\Settings::class)->set($p, ['tutor_model' => 'fake/tutor', 'consent' => true]);`,
    ].join(' '));
    await page.setViewportSize(size);
    await openStudentHome(page, student.email);
    await page.goto(`/courses/${student.workspace}/sessions/${student.session}`);
    await page.getByRole('heading', { name: 'Your tutor' }).waitFor({ state: 'attached' });
    await page.waitForLoadState('load');

    return student;
}

test('the chips ask the tutor, and the + menu holds a question, a card and a note', async ({ page }) => {
    await openReadySession(page);
    const chat = page.locator('.chat');
    const quick = chat.getByRole('list', { name: 'Quick asks' });
    for (const name of ['Quiz me', 'Cards', 'Note this', 'Where are we']) {
        await expect(quick.getByRole('button', { name: new RegExp(`^${name}`) })).toBeVisible();
    }
    await expect(chat.getByText(/fake\/tutor · \$0\.00 of \$2\.00 this session/)).toBeVisible();

    // One tap asks: the student's words show at once and the tutor answers.
    await quick.getByRole('button', { name: 'Where are we' }).click();
    await expect(chat.locator('.chat-turn.is-me').last()).toHaveText('Where are we? What is done and what is left?');
    await expect(chat.locator('li[wire\\:key^="turn-"].is-tutor .chat-markdown')).toHaveCount(1, { timeout: 15_000 });

    // The + menu: attach, ask a question, a card, a note.
    await chat.getByRole('button', { name: /^More: attach/ }).click();
    const menu = chat.getByRole('group', { name: 'More', exact: true });
    for (const name of ['Attach a note, file or picture', 'Ask a question', 'New flashcard', 'Write a note']) {
        await expect(menu.getByRole('button', { name })).toBeVisible();
    }
    await menu.getByRole('button', { name: 'Ask a question' }).click();
    const panel = page.locator('#session-dialog');
    await expect(panel.getByRole('heading', { name: 'Ask a question' })).toBeVisible();
    await panel.getByLabel('Your question', { exact: true }).fill('Why does a left join keep unmatched rows?');
    await panel.getByRole('button', { name: 'Keep question' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'The question is kept.' })).toBeVisible();
    await expect(panel).not.toBeVisible();
});

test('the tutor marks a topic as its own, with a tap to undo it, and the end screen starts there', async ({ page }) => {
    const student = makeStudentWithSession();
    tinker([
        `$p = app(\\App\\Identity\\PrincipalFactory::class)->forUser(\\App\\Models\\User::query()->where('email', '${student.email}')->firstOrFail(), 'web');`,
        `app(\\App\\Engine\\Settings::class)->setKey($p, 'sk-or-browser-test-0000000000');`,
        `app(\\App\\Engine\\Settings::class)->set($p, ['tutor_model' => 'fake/tutor', 'consent' => true]);`,
        // A topic with no status yet, and the session on it.
        `$w = '${student.workspace}'; $joins = collect(app(\\App\\Study\\Topics::class)->list($p, $w))->firstWhere('name', 'Joins');`,
        `$self = app(\\App\\Study\\Topics::class)->create($p, $w, 'Self joins', $joins->moduleId); app(\\App\\Study\\Sessions::class)->setTopic($p, '${student.session}', $self->id);`,
    ].join(' '));
    await page.setViewportSize({ width: 1440, height: 900 });
    await openStudentHome(page, student.email);
    await page.goto(`/courses/${student.workspace}/sessions/${student.session}`);
    await page.getByRole('heading', { level: 1, name: 'Self joins' }).waitFor();
    await page.waitForLoadState('load');
    const chat = page.locator('.chat');
    const box = chat.getByLabel('Write to your tutor');
    await box.fill('I think I get this, mark it');
    await box.press('Enter');

    const where = chat.getByRole('list', { name: 'Where you stand' });
    await expect(where).toContainText('Self joins: understood, marked by the tutor', { timeout: 15_000 });
    expect(await analyseChat(page)).toEqual([]);
    // The rail shows it as the tutor's, and Undo takes it back.
    const rail = page.getByRole('complementary', { name: /Topics, material/ });
    await expect(rail.getByRole('button', { name: /Self joins.*marked by the tutor/ })).toBeVisible();
    await where.getByRole('button', { name: /^Undo/ }).click();
    await expect(where).toContainText('Self joins: understood, undone');
    await expect(rail.getByRole('button', { name: /Self joins.*Not started/ })).toBeVisible();

    // The end screen: the topic starts on what the tutor said, and the choice is the student's.
    await page.getByRole('button', { name: 'End', exact: true }).click();
    const end = page.locator('#session-dialog');
    await expect(end.getByRole('heading', { name: 'End this session?' })).toBeVisible();
    const group = end.getByRole('radiogroup', { name: 'Where you stand on Self joins' });
    await expect(group.getByLabel('Understood')).toBeChecked();
    await group.getByLabel('Covered').check();
    await end.getByRole('button', { name: 'End session' }).click();
    await expect(end.getByRole('heading', { name: 'Session ended' })).toBeVisible();
    const status = JSON.parse(tinker(`echo json_encode(DB::table('topics')->where('workspace_id', '${student.workspace}')->where('name', 'Self joins')->first(['status', 'status_by']));`));
    expect([status.status, status.status_by]).toEqual(['covered', 'student']);
});

test('on a phone the rail is a sheet from the topic, and the box stays in view', async ({ page }) => {
    await openReadySession(page, { width: 390, height: 844 });
    await expect(page.getByRole('complementary', { name: /Topics, material/ })).toBeHidden();
    const topic = page.getByRole('button', { name: /^Joins/ }).first();
    await expect(topic).toBeVisible();
    await topic.click();
    const sheet = page.locator('#session-rail-sheet');
    await expect(sheet.getByRole('heading', { name: 'Week 1: Relational model', exact: true })).toBeVisible();
    await expect(sheet.getByRole('button', { name: /^Primary and foreign keys/ })).toBeVisible();
    await sheet.getByRole('button', { name: /^Primary and foreign keys/ }).click();
    await expect(sheet.getByRole('button', { name: /^Primary and foreign keys/ })).toHaveAttribute('aria-current', 'true');
    await page.keyboard.press('Escape');
    await expect(sheet).not.toBeVisible();

    // The box is in view above the tab bar, and the page does not scroll sideways.
    const box = page.getByLabel('Write to your tutor');
    await expect(box).toBeVisible();
    const rect = await box.boundingBox();
    expect(rect.y + rect.height).toBeLessThanOrEqual(844);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
});

async function analyseChat(page) {
    return (await new AxeBuilder({ page }).include('.chat').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze())
        .violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target).join(' ')}`);
}


/*
 * The helper everywhere (docs/specs/vistud-2-blueprint.md §3.6.5): the ✦ menus on a card, a question, a selection of a note and a
 * file, and Ask in the top bar. They live in this file because it owns the fake service: one at a time may point the app at it.
 */

const ai = async (page, student, path, viewport = { width: 1440, height: 900 }) => {
    turnOnAi(student.email);
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.setViewportSize(viewport);
    await openStudentHome(page, student.email);
    await page.goto(path);
    await page.waitForLoadState('load');
};
const sheet = (page) => page.locator('#ai-sheet');
/* Ask: a button in the top bar, and on a phone the tab bar's (the top bar's is hidden under 768 px). */
const askButton = (page) => page.locator('.app-topbar, .app-tabbar').getByRole('button', { name: 'Ask', exact: true });
const analyseSheet = async (page) => (await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze())
    .violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target).join(' ')}`);
const cardRow = (page, front) => page.locator('.card-row').filter({ hasText: front });

test('helper: a card\'s ✦ shows it before and after, and Keep changes the card', async ({ page }) => {
    const student = makeStudentWithCards();
    await ai(page, student, `/courses/${student.workspace}/flashcards`);
    const row = cardRow(page, 'What does a LEFT JOIN keep?');
    await row.getByRole('button', { name: /^AI help with/ }).click();
    await row.getByRole('button', { name: 'Improve', exact: true }).click();

    await expect(sheet(page).getByRole('heading', { name: 'Improve this card' })).toBeVisible();
    await expect(sheet(page).getByRole('heading', { name: 'After' })).toBeVisible({ timeout: 15_000 });
    await expect(sheet(page).getByRole('region', { name: 'After' })).toContainText('matched or not');
    await expect(sheet(page).getByRole('region', { name: 'Before' })).toContainText('with NULLs where the right table has no match');
    // Nothing is changed until it is kept.
    await expect(cardRow(page, 'with NULLs where the right table has no match')).toHaveCount(1);
    await sheet(page).getByRole('button', { name: 'Keep', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'The card is changed.' })).toBeVisible();
    await expect(cardRow(page, 'Every row of the left table, matched or not.')).toHaveCount(1);
});

test('helper: discarding leaves the card, and two more like this are added as the AI\'s', async ({ page }) => {
    const student = makeStudentWithCards();
    await ai(page, student, `/courses/${student.workspace}/flashcards`);
    const row = cardRow(page, 'What does an INNER JOIN keep?');
    await row.getByRole('button', { name: /^AI help with/ }).click();
    await row.getByRole('button', { name: 'Shorter', exact: true }).click();
    await sheet(page).getByRole('heading', { name: 'After' }).waitFor({ timeout: 15_000 });
    await sheet(page).getByRole('button', { name: 'Discard' }).click();
    await expect(cardRow(page, 'Only the rows that match on both sides.')).toHaveCount(1);

    await row.getByRole('button', { name: /^AI help with/ }).click();
    await row.getByRole('button', { name: 'Two more like this' }).click();
    await expect(sheet(page).getByRole('button', { name: 'Add 2 cards' })).toBeVisible({ timeout: 15_000 });
    await sheet(page).getByRole('button', { name: 'Add 2 cards' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Added 2 cards.' })).toBeVisible();
    await expect(cardRow(page, 'What does a RIGHT JOIN keep?')).toContainText('Made with an AI');
    await expect(page.locator('.card-row')).toHaveCount(7);
});

test('helper: Make cards from… asks what from, shows the cards ticked, and adds only the ones kept', async ({ page }) => {
    const student = makeStudentWithCards();
    await ai(page, student, `/courses/${student.workspace}/flashcards`);
    await page.getByRole('button', { name: 'Make cards from…' }).click();
    await expect(sheet(page).getByRole('heading', { name: 'Make cards from…' })).toBeVisible();
    await sheet(page).getByRole('button', { name: 'Make cards' }).click();
    await expect(sheet(page)).toContainText('Choose what to make cards from.');
    await sheet(page).getByLabel('Topic', { exact: true }).selectOption({ label: 'Joins' });
    await sheet(page).getByLabel('How many').selectOption('5');
    await sheet(page).getByRole('button', { name: 'Make cards' }).click();

    await expect(sheet(page).getByRole('button', { name: 'Add 2 cards' })).toBeVisible({ timeout: 15_000 });
    await sheet(page).getByRole('checkbox', { name: 'Add card 2' }).uncheck();
    await expect(sheet(page).getByRole('button', { name: 'Add 1 card' })).toBeVisible();
    await sheet(page).getByRole('button', { name: 'Add 1 card' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Added 1 card.' })).toBeVisible();
    await expect(cardRow(page, 'What does the scheduler decide?')).toContainText('Made with an AI');
    await expect(cardRow(page, 'What is round robin?')).toHaveCount(0);
});

test('helper: a question is clarified on its page, and Keep puts the clearer words in the box', async ({ page }) => {
    const student = makeStudentWithTopics();
    await ai(page, student, `/courses/${student.workspace}/questions`);
    await page.getByRole('link', { name: 'Why does a left join keep the unmatched rows?' }).first().click();
    await page.getByRole('heading', { level: 1, name: 'Question' }).waitFor();
    await page.waitForLoadState('load');
    await page.getByRole('button', { name: /^AI help with/ }).click();
    await page.getByRole('button', { name: 'Clarify', exact: true }).click();
    await expect(sheet(page).getByRole('heading', { name: 'Clarify the question' })).toBeVisible();
    await expect(sheet(page).getByRole('region', { name: 'After' })).toContainText('Why does a left join keep the unmatched rows?', { timeout: 15_000 });
    await sheet(page).getByRole('button', { name: 'Use this' }).click();
    await expect(page.getByLabel("What don't you get?")).toHaveValue('Why does a left join keep the unmatched rows?');
});

test('helper: a file is read for its summary, makes a note, and makes cards, from its ✦', async ({ page }) => {
    const student = makeStudentWithModulePage();
    await ai(page, student, `${student.module}?tab=files`);
    const row = page.locator('.item-row').filter({ hasText: 'Lecture 3.txt' });
    await row.getByRole('button', { name: /^AI help with/ }).click();
    await row.getByRole('button', { name: 'Summarise', exact: true }).click();
    await expect(sheet(page)).toContainText('Scheduling policies and their trade-offs.', { timeout: 15_000 });
    await sheet(page).getByRole('button', { name: 'Close', exact: true }).last().click();

    await row.getByRole('button', { name: /^AI help with/ }).click();
    await row.getByRole('button', { name: 'Make cards from it' }).click();
    await expect(sheet(page).getByRole('button', { name: 'Add 2 cards' })).toBeVisible({ timeout: 15_000 });
    await expect(sheet(page).getByLabel('Front of card 1')).toHaveValue('What does the scheduler decide?');
    await sheet(page).getByRole('button', { name: 'Add 2 cards' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Added 2 cards.' })).toBeVisible();

    await row.getByRole('button', { name: /^AI help with/ }).click();
    await row.getByRole('button', { name: 'Make a note from it' }).click();
    await expect(sheet(page)).toContainText('Notes · Lecture 3', { timeout: 15_000 });
    await sheet(page).getByRole('link', { name: 'Open it' }).click();
    await page.getByRole('heading', { level: 1, name: 'Notes · Lecture 3' }).waitFor();
});

test('helper: a selection of a note is shortened, and only Keep puts it in the note', async ({ page }) => {
    const note = makeStudentWithNote();
    await ai(page, note, note.url);
    const editor = page.locator('.ProseMirror').first();
    await editor.waitFor();
    await editor.click();
    await editor.press('Control+A');
    await page.getByRole('button', { name: 'AI help with the selection' }).click();
    await page.getByRole('menuitem', { name: 'Shorten' }).click();
    await expect(sheet(page).getByRole('heading', { name: 'Shorten this' })).toBeVisible();
    await expect(sheet(page).getByRole('button', { name: 'Replace the text' })).toBeVisible({ timeout: 15_000 });
    await expect(editor).toContainText('Mitosis');
    await sheet(page).getByRole('button', { name: 'Replace the text' }).click();
    await expect(editor).toContainText('Four conditions cause a deadlock.');
    await expect(editor).not.toContainText('Mitosis');
});

test('helper: Ask answers a quick question about the course on a phone, and the way to the tutor is one line away', async ({ page }) => {
    const student = makeStudentWithTopics();
    await ai(page, student, `/courses/${student.workspace}`, { width: 390, height: 844 });
    await askButton(page).click();
    const ask = page.locator('#ask-sheet');
    await expect(ask.getByRole('heading', { name: 'Ask' })).toBeVisible();
    await expect(ask.getByLabel('Your question', { exact: true })).toBeFocused();
    await ask.getByLabel('Your question', { exact: true }).fill('What did I find hard?');
    await ask.getByLabel('Your question', { exact: true }).press('Enter');
    await expect(ask.getByRole('log')).toContainText('You found Deadlocks hard, and Joins confusing.', { timeout: 15_000 });
    await expect(ask.getByRole('log')).toContainText('What did I find hard?');
    await expect(ask.getByText('Need teaching?')).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
    await ask.getByRole('button', { name: 'Start a session' }).click();
    await page.waitForURL(/\/sessions\//);
});

for (const [name, viewport] of Object.entries({ desktop: { width: 1440, height: 900 }, phone: { width: 390, height: 844 } })) {
    test(`helper: every colour of the sheets comes from a token: ${name}`, async ({ page }) => {
        const student = makeStudentWithCards();
        await ai(page, student, `/courses/${student.workspace}/flashcards`, viewport);
        await useSentinelTheme(page);
        const row = cardRow(page, 'What does a LEFT JOIN keep?');
        await row.getByRole('button', { name: /^AI help with/ }).click();
        const states = { menu: await foreignColours(page) };
        await row.getByRole('button', { name: 'Improve', exact: true }).click();
        await sheet(page).getByRole('heading', { name: 'After' }).waitFor({ timeout: 15_000 });
        states['before and after'] = await foreignColours(page);
        await sheet(page).getByRole('button', { name: 'Discard' }).click();
        await askButton(page).click();
        await page.locator('#ask-sheet').getByLabel('Your question', { exact: true }).fill('Hello?');
        await page.locator('#ask-sheet').getByLabel('Your question', { exact: true }).press('Enter');
        await expect(page.locator('#ask-sheet').getByRole('log')).toContainText('You found Deadlocks hard', { timeout: 15_000 });
        states.ask = await foreignColours(page);
        for (const [state, colours] of Object.entries(states)) {
            expect(colours, `${state}: colours not from a token`).toEqual([]);
        }
    });
}

for (const theme of THEMES) {
    test(`helper: axe finds no violations in the ✦ menu, its sheet and Ask: ${theme}`, async ({ page }) => {
        const student = makeStudentWithCards();
        await ai(page, student, `/courses/${student.workspace}/flashcards`);
        await useTheme(page, theme);
        const row = cardRow(page, 'What does a LEFT JOIN keep?');
        await row.getByRole('button', { name: /^AI help with/ }).click();
        expect(await analyseSheet(page), 'menu').toEqual([]);
        await row.getByRole('button', { name: 'Improve', exact: true }).click();
        await sheet(page).getByRole('heading', { name: 'After' }).waitFor({ timeout: 15_000 });
        expect(await analyseSheet(page), 'sheet').toEqual([]);
        await sheet(page).getByRole('button', { name: 'Discard' }).click();
        await page.getByRole('button', { name: 'Make cards from…' }).click();
        await sheet(page).getByLabel('How many').waitFor();
        expect(await analyseSheet(page), 'choose').toEqual([]);
        await page.keyboard.press('Escape');
        await askButton(page).click();
        await page.locator('#ask-sheet').getByLabel('Your question', { exact: true }).waitFor();
        expect(await analyseSheet(page), 'ask').toEqual([]);
    });
}

/*
 * The course guide (docs/specs/vistud-2-blueprint.md, Phase 8): the New course page, the talk with the tutor that sets the
 * course up (the course only), and the talk that adds its modules, from Modules. They are here because this file owns the
 * fake service.
 */
const timetable = 'About the Module: operating systems and their technologies. Week 1: OS Structure | Processes & Threads. Week 2: Concurrency & Scheduling | Memory Management. Week 3: Virtual Memory | Storage & IO.';
const weeks = 'Add Week 1: OS Structure | Processes & Threads, Week 2: Concurrency & Scheduling | Memory Management and Week 3: Virtual Memory | Storage & IO.';
const guideProposal = (page) => page.getByRole('region', { name: 'I would add' });

async function startGuide(page, viewport) {
    await ai(page, { email: makeStudentAccount() }, '/courses/new', viewport);
    await page.getByRole('heading', { level: 1, name: 'New course' }).waitFor();
    await page.getByLabel('Name', { exact: true }).fill('Operating Systems');
    await page.getByRole('button', { name: 'Create course' }).click();
    await page.getByRole('heading', { level: 1, name: 'Set up with the AI' }).waitFor();
    await page.waitForLoadState('load');
}

test('guide: a course is made on its own page and set up by the guide, and the weeks are added from Modules, a few at a time', async ({ page }) => {
    await ai(page, { email: makeStudentAccount() }, '/');
    await page.locator('main').getByRole('link', { name: 'New course' }).first().click();
    await page.getByRole('heading', { level: 1, name: 'New course' }).waitFor();
    await expect(page.getByRole('radio', { name: /Guide me/ })).toBeChecked();
    await page.getByLabel('Name', { exact: true }).fill('Operating Systems');
    await page.getByText('Green', { exact: true }).click({ force: true });
    await page.getByRole('button', { name: 'Create course' }).click();

    // The guide asks first; nothing is sent to the AI until the student writes.
    await page.getByRole('heading', { level: 1, name: 'Set up with the AI' }).waitFor();
    const log = page.getByRole('log', { name: 'Talk with the guide' });
    await expect(log).toContainText("Let's set up Operating Systems");
    await page.getByLabel('Your message').fill(timetable);
    await page.getByRole('button', { name: 'Send' }).click();
    await expect(guideProposal(page)).toBeVisible({ timeout: 15_000 });
    await expect(log).toContainText('I found what the course is about.');

    // Setting a course up is the course only: what it is about and how it is assessed, never its weeks.
    await expect(guideProposal(page).getByRole('checkbox', { name: /What the course is about/ })).toBeChecked();
    await expect(guideProposal(page).getByRole('checkbox', { name: /Coursework 1/ })).toBeChecked();
    await expect(guideProposal(page).getByRole('checkbox', { name: /Week/ })).toHaveCount(0);
    await guideProposal(page).getByRole('button', { name: /^Add what is ticked \(2\)/ }).click();
    await expect(log).toContainText('Added: the About text and 1 assessment.');
    await expect(log).toContainText('The course is set up.');
    await expect(guideProposal(page)).toHaveCount(0);

    // The weeks come next, on Modules, where the guide has read what the course is about.
    const id = page.url().match(/courses\/([^/]+)\/guide/)[1];
    await page.getByRole('link', { name: 'Go to Modules' }).first().click();
    await page.getByRole('heading', { level: 1, name: 'Modules' }).waitFor();
    await expect(page.locator('main').getByText('No modules yet')).toBeVisible();
    await page.locator('main').getByRole('link', { name: 'Add with the AI', exact: true }).click();
    await page.getByRole('heading', { level: 1, name: 'Add modules' }).waitFor();
    await expect(page.getByRole('log', { name: 'Talk with the guide' })).toContainText('I have read what Operating Systems is about');
    await page.getByLabel('Your message').fill(weeks);
    await page.getByRole('button', { name: 'Send' }).click();
    await expect(guideProposal(page)).toBeVisible({ timeout: 15_000 });
    await expect(guideProposal(page).getByRole('checkbox', { name: /Week 3/ })).toBeChecked();

    // Week 3 is for later: untick it, and add the rest.
    await guideProposal(page).getByRole('checkbox', { name: /Week 3/ }).uncheck();
    await guideProposal(page).getByRole('button', { name: /^Add what is ticked \(2\)/ }).click();
    await expect(page.getByRole('log', { name: 'Talk with the guide' })).toContainText('Added: 2 modules.');
    await page.goto(`/courses/${id}/modules`);
    await expect(page.locator('main').getByRole('link', { name: /Week 1: OS Structure/ })).toBeVisible();
    await expect(page.locator('main').getByRole('link', { name: /Week 3/ })).toHaveCount(0);

    // Next week: week 3 comes in, and the weeks that are there are not added twice.
    await page.locator('main').getByRole('link', { name: 'Add with the AI', exact: true }).click();
    await page.getByRole('heading', { level: 1, name: 'Add modules' }).waitFor();
    await page.getByLabel('Your message').fill(weeks);
    await page.getByRole('button', { name: 'Send' }).click();
    await expect(guideProposal(page)).toBeVisible({ timeout: 15_000 });
    await guideProposal(page).getByRole('button', { name: /^Add what is ticked/ }).click();
    await expect(page.getByRole('log', { name: 'Talk with the guide' })).toContainText('Added: 1 module.');
    await page.goto(`/courses/${id}/modules`);
    await expect(page.locator('main').getByRole('link', { name: /Week 3: Virtual Memory/ })).toBeVisible();
    await expect(page.locator('main').getByRole('link', { name: /Week 1: OS Structure/ })).toHaveCount(1);
});

test('guide: choosing to do it myself goes to the course page, and the guide is one step away there', async ({ page }) => {
    await ai(page, { email: makeStudentAccount() }, '/courses/new');
    await page.getByLabel('Name', { exact: true }).fill('Biology');
    await page.getByRole('radio', { name: /I'll do it myself/ }).check({ force: true });
    await page.getByRole('button', { name: 'Create course' }).click();
    await page.getByRole('heading', { level: 1, name: 'Biology' }).waitFor();
    // Its setup sheet is open (the by-hand way); closing it leaves the course page, with the guide in its ⋯ menu.
    await expect(page.locator('#course-setup')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.locator('#course-setup')).toBeHidden();
    await page.getByRole('button', { name: 'More for Biology' }).click();
    await page.getByRole('link', { name: 'Set up with the AI' }).click();
    await page.getByRole('heading', { level: 1, name: 'Set up with the AI' }).waitFor();
});

for (const [name, viewport] of Object.entries({ desktop: { width: 1440, height: 900 }, phone: { width: 390, height: 844 } })) {
    test(`guide: the New course page and the guide fit and use only tokens: ${name}`, async ({ page }) => {
        await ai(page, { email: makeStudentAccount() }, '/courses/new', viewport);
        await page.getByRole('heading', { level: 1, name: 'New course' }).waitFor();
        await page.getByLabel('Name', { exact: true }).fill('Operating Systems');
        await useSentinelTheme(page);
        const states = { 'new course': await foreignColours(page) };
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
        await page.locator('.course-new-more summary').click();
        states['new course details'] = await foreignColours(page);

        await page.getByRole('button', { name: 'Create course' }).click();
        await page.getByRole('heading', { level: 1, name: 'Set up with the AI' }).waitFor();
        await page.getByLabel('Your message').fill(timetable);
        await page.getByRole('button', { name: 'Send' }).click();
        await expect(guideProposal(page)).toBeVisible({ timeout: 15_000 });
        await useSentinelTheme(page);
        states['the guide with a proposal'] = await foreignColours(page);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
        for (const [state, colours] of Object.entries(states)) {
            expect(colours, `${state}: colours not from a token`).toEqual([]);
        }
    });
}

for (const theme of THEMES) {
    test(`guide: axe finds no violations on the New course page and the guide: ${theme}`, async ({ page }) => {
        await ai(page, { email: makeStudentAccount() }, '/courses/new');
        await page.getByRole('heading', { level: 1, name: 'New course' }).waitFor();
        await useTheme(page, theme);
        expect(await analyseSheet(page), 'new course').toEqual([]);
        await page.getByLabel('Name', { exact: true }).fill('Operating Systems');
        await page.getByRole('button', { name: 'Create course' }).click();
        await page.getByRole('heading', { level: 1, name: 'Set up with the AI' }).waitFor();
        await page.getByLabel('Your message').fill(timetable);
        await page.getByRole('button', { name: 'Send' }).click();
        await expect(guideProposal(page)).toBeVisible({ timeout: 15_000 });
        await useTheme(page, theme);
        expect(await analyseSheet(page), 'guide').toEqual([]);

        // The talk that adds modules, with its proposal.
        const id = page.url().match(/courses\/([^/]+)\/guide/)[1];
        await page.goto(`/courses/${id}/guide?for=modules`);
        await page.getByRole('heading', { level: 1, name: 'Add modules' }).waitFor();
        await page.getByLabel('Your message').fill(weeks);
        await page.getByRole('button', { name: 'Send' }).click();
        await expect(guideProposal(page)).toBeVisible({ timeout: 15_000 });
        await useTheme(page, theme);
        expect(await analyseSheet(page), 'module guide').toEqual([]);
    });
}

// Screenshots for review (PREVIEWS=1): the New course page and the guide, with a proposal, on a computer and a phone.
for (const [size, viewport] of Object.entries({ desktop: { width: 1440, height: 900 }, mobile: { width: 390, height: 844 } })) {
    test(`guide: previews ${size}`, async ({ page }) => {
        test.skip(!process.env.PREVIEWS, 'Set PREVIEWS=1 to regenerate the review screenshots.');
        const out = (name) => `docs/design/previews/${name}.png`;
        await ai(page, { email: makeStudentAccount() }, '/courses/new', viewport);
        await page.getByRole('heading', { level: 1, name: 'New course' }).waitFor();
        await useTheme(page, 'vistud-light');
        await page.getByLabel('Name', { exact: true }).fill('Operating Systems');
        await page.getByText('Green', { exact: true }).click({ force: true });
        await page.evaluate(() => document.activeElement?.blur());
        await page.screenshot({ path: out(`new-course-${size}-vistud-light`), fullPage: size === 'mobile' });
        await page.locator('.course-new-more summary').click();
        await page.screenshot({ path: out(`new-course-${size}-vistud-light-details`), fullPage: true });
        await useTheme(page, 'vistud-dark');
        await page.screenshot({ path: out(`new-course-${size}-vistud-dark`), fullPage: size === 'mobile' });
        await useTheme(page, 'vistud-light');

        await page.getByRole('button', { name: 'Create course' }).click();
        await page.getByRole('heading', { level: 1, name: 'Set up with the AI' }).waitFor();
        await page.screenshot({ path: out(`guide-${size}-vistud-light`), fullPage: size === 'mobile' });
        await page.getByLabel('Your message').fill(timetable);
        await page.getByRole('button', { name: 'Send' }).click();
        await expect(guideProposal(page)).toBeVisible({ timeout: 15_000 });
        await page.evaluate(() => document.activeElement?.blur());
        await page.screenshot({ path: out(`guide-${size}-vistud-light-proposal`), fullPage: true });
        await useTheme(page, 'vistud-dark');
        await page.screenshot({ path: out(`guide-${size}-vistud-dark-proposal`), fullPage: true });
    });
}
