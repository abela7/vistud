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
