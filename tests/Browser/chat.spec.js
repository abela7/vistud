import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { spawn, execFileSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { makeStudentWithSession, openStudentHome } from './support.js';

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
    await page.goto(`/workspaces/${student.workspace}/sessions/${student.session}`);
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
    await page.goto(`/workspaces/${student.workspace}/sessions/${student.session}`);
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
    await page.goto(`/workspaces/${student.workspace}/sessions/${student.session}`);
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
    await page.goto(`/workspaces/${student.workspace}/sessions/${student.session}`);
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
    await page.goto(`/workspaces/${student.workspace}/sessions/${student.session}`);
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
    await panel.getByLabel('Your question').fill('Why does a left join keep unmatched rows?');
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
    await page.goto(`/workspaces/${student.workspace}/sessions/${student.session}`);
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
