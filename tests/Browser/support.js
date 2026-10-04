import { execFileSync } from 'node:child_process';
import { createHmac } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

export const sentinel = JSON.parse(readFileSync(new URL('./fixtures/sentinel-theme.json', import.meta.url)));

export const THEMES = ['vistud-light', 'vistud-dark', 'ember'];

/** Switch the theme in place, as a saved preset or custom theme would, without reloading. */
export async function useTheme(page, theme) {
    // Checks must see the styled page: a heading can be visible before the stylesheet has loaded.
    await page.waitForLoadState('load');
    await page.evaluate((id) => {
        document.documentElement.dataset.theme = id;
    }, theme);
}

/**
 * Inject the sentinel theme (ADR 0003 §6.3): every token and gradient stop
 * has its own colour. Call page.emulateMedia({ reducedMotion: 'reduce' })
 * first, so colour transitions finish at once.
 */
export async function useSentinelTheme(page) {
    await page.waitForLoadState('load');
    await page.addStyleTag({ content: sentinel.css });
    await useTheme(page, 'sentinel');
}

/**
 * Every colour the page computes: each element and its ::before, ::after
 * and ::placeholder, across every colour-bearing property, gradients and
 * shadows included. Returns the colours that aren't sentinel values.
 */
export async function foreignColours(page) {
    return page.evaluate((allowed) => {
        const allowedSet = new Set(allowed);
        const properties = [
            'color', 'background-color', 'background-image',
            'border-top-color', 'border-right-color', 'border-bottom-color', 'border-left-color',
            'outline-color', 'text-decoration-color', 'caret-color', 'column-rule-color',
            'box-shadow', 'text-shadow', 'accent-color', 'scrollbar-color',
            '-webkit-tap-highlight-color', '-webkit-text-fill-color', '-webkit-text-stroke-color', 'text-emphasis-color',
        ];
        const svgPaint = new Set(['stop', 'feflood', 'fediffuselighting', 'fespecularlighting', 'fedropshadow']);
        const found = new Set();
        const check = (element, pseudo) => {
            const style = getComputedStyle(element, pseudo);
            if (pseudo && (style.content === 'none' || style.content === 'normal') && pseudo !== '::placeholder') return;
            // SVG paint only matters on SVG elements; every HTML element computes a default black fill.
            const props = element instanceof SVGElement
                ? [...properties, 'fill', 'stroke', ...(svgPaint.has(element.localName) ? ['stop-color', 'flood-color', 'lighting-color'] : [])]
                : properties;
            for (const property of props) {
                const value = style.getPropertyValue(property);
                for (const match of value.matchAll(/rgba?\(([^)]*)\)|color\([^)]*\)/g)) {
                    if (!match[1]) {
                        found.add(`${property}: ${match[0]}`);
                        continue;
                    }
                    const parts = match[1].split(/[\s,/]+/).filter(Boolean);
                    const alpha = parts.length > 3 ? parseFloat(parts[3]) : 1;
                    if (alpha === 0) continue; // transparent
                    const rgb = parts.slice(0, 3).join(', ');
                    if (!allowedSet.has(rgb)) {
                        const where = element.id ? `#${element.id}` : element.className?.baseVal ?? element.className ?? element.localName;
                        found.add(`${element.localName}${pseudo ?? ''} (${String(where).slice(0, 40)}) ${property}: ${match[0]}`);
                    }
                }
            }
        };
        for (const element of document.querySelectorAll('*')) {
            if (element.closest('head')) continue;
            check(element, null);
            check(element, '::before');
            check(element, '::after');
            if (element.matches('input, textarea')) check(element, '::placeholder');
        }
        return [...found];
    }, sentinel.colors);
}

/** A mark that survives only while the page is not reloaded. */
export async function markPage(page) {
    await page.evaluate(() => {
        window.__vistudNoReload = true;
    });
}

export async function wasReloaded(page) {
    return page.evaluate(() => window.__vistudNoReload !== true);
}

const appRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..');

/** An account in the app database the browser server uses. */
function makeAccount(twoFactor, admin = false, name = null) {
    const email = `browser-${Date.now()}-${Math.random().toString(16).slice(2)}@example.test`;
    const php = process.env.PHP_BINARY || 'php';
    const factory = `\\App\\Models\\User::factory()->student()${admin ? '->admin()' : ''}${twoFactor ? '->twoFactor()' : ''}`;
    const attributes = `['email' => '${email}'${name ? `, 'name' => '${name}'` : ''}]`;
    const code = `if (!\\App\\Models\\User::query()->where('email', '${email}')->exists()) { ${factory}->create(${attributes}); }`;
    execFileSync(php, ['artisan', 'tinker', '--execute', code], { cwd: appRoot, stdio: 'pipe' });

    return email;
}

/** A confirmed two-factor account in the app database the browser server uses. */
export function makeTwoFactorAccount() {
    return makeAccount(true);
}

/** A student with a given name (letters and spaces only), for screens that show it. */
export function makeNamedStudent(name) {
    return makeAccount(false, false, name);
}

/** A student whose journal holds the sample entries (php artisan vistud:journal:sample). */
export function makeStudentWithJournal() {
    const email = makeAccount(false);
    execFileSync(process.env.PHP_BINARY || 'php', ['artisan', 'vistud:journal:sample', email], { cwd: appRoot, stdio: 'pipe' });

    return email;
}

/** A student with workspaces, made through the real service: [[name, colour, icon], ...]. */
export function makeStudentWithWorkspaces(workspaces) {
    const email = makeAccount(false);
    const creates = workspaces
        .map(([name, colour, icon]) => `$w->create($p, ['name' => '${name}', 'colour' => '${colour}', 'icon' => '${icon}']);`)
        .join(' ');
    const code = `$p = app(\\App\\Identity\\PrincipalFactory::class)->forUser(\\App\\Models\\User::query()->where('email', '${email}')->firstOrFail(), 'web'); $w = app(\\App\\Study\\Workspaces::class); ${creates}`;
    execFileSync(process.env.PHP_BINARY || 'php', ['artisan', 'tinker', '--execute', code], { cwd: appRoot, stdio: 'pipe' });

    return email;
}

/** A student with a Biology workspace holding two modules and some folders, made through the real services. */
export function makeStudentWithModules() {
    const email = makeAccount(false);
    const code = [
        `$p = app(\\App\\Identity\\PrincipalFactory::class)->forUser(\\App\\Models\\User::query()->where('email', '${email}')->firstOrFail(), 'web');`,
        `$w = app(\\App\\Study\\Workspaces::class)->create($p, ['name' => 'Biology', 'colour' => 'green', 'icon' => 'microscope']);`,
        `$m = app(\\App\\Study\\Modules::class); $f = app(\\App\\Study\\Folders::class);`,
        `$cells = $m->create($p, $w->id, ['title' => 'Week 1: Cells', 'starts_on' => '2026-09-08', 'ends_on' => '2026-09-14']);`,
        `$m->create($p, $w->id, ['title' => 'Week 2: Cell division']);`,
        `$labs = $f->create($p, 'module', $cells->id, 'Labs'); $f->create($p, 'folder', $labs->id, 'Lab 1: microscopes'); $f->create($p, 'module', $cells->id, 'Reading');`,
    ].join(' ');
    execFileSync(process.env.PHP_BINARY || 'php', ['artisan', 'tinker', '--execute', code], { cwd: appRoot, stdio: 'pipe' });

    return email;
}

/**
 * makeStudentWithModules(), plus the note "Mitosis vs meiosis" in Week 2 and
 * an empty one in Labs. Returns the email and the written note's page.
 */
export function makeStudentWithNote() {
    const email = makeStudentWithModules();
    return { email, ...noteFor(email) };
}

function noteFor(email) {
    const paragraph = (...parts) => `['type' => 'paragraph', 'content' => [${parts.join(', ')}]]`;
    const text = (t, mark = null) => `['type' => 'text', 'text' => '${t}'${mark ? `, 'marks' => [['type' => '${mark}']]` : ''}]`;
    const item = (...parts) => `['type' => 'listItem', 'content' => [${paragraph(...parts)}]]`;
    const heading = (t) => `['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [${text(t)}]]`;
    const doc = `['type' => 'doc', 'content' => [${[
        paragraph(text('Both are ways a cell divides, but they have different jobs.')),
        heading('Mitosis'),
        `['type' => 'bulletList', 'content' => [${[item(text('For growth and repair, like healing a cut.')), item(text('Makes '), text('2', 'bold'), text(' cells, each a copy of the original.'))].join(', ')}]]`,
        heading('Meiosis'),
        `['type' => 'bulletList', 'content' => [${[item(text('Makes egg and sperm cells.')), item(text('Makes '), text('4', 'bold'), text(' cells, each different.'))].join(', ')}]]`,
        `['type' => 'blockquote', 'content' => [${paragraph(text('Trick to remember: '), text('meiosis', 'bold'), text(' makes '), text('me', 'bold'), text(', the cells that make a new person.'))}]]`,
    ].join(', ')}]]`;
    const code = [
        `$p = app(\\App\\Identity\\PrincipalFactory::class)->forUser(\\App\\Models\\User::query()->where('email', '${email}')->firstOrFail(), 'web');`,
        `$w = app(\\App\\Study\\Workspaces::class)->list($p)[0]; $mods = app(\\App\\Study\\Modules::class)->list($p, $w->id);`,
        `$labs = collect(app(\\App\\Study\\Folders::class)->tree($p, $w->id))->firstWhere('name', 'Labs');`,
        `$notes = app(\\App\\Study\\Notes::class); $n = $notes->create($p, 'module', $mods[1]->id, 'Mitosis vs meiosis');`,
        `$notes->save($p, $n->id, ['base_version' => 1, 'save_id' => 'seed-0001', 'client_id' => 'seed-0001', 'title' => 'Mitosis vs meiosis', 'doc' => ${doc}]);`,
        `$e = $notes->create($p, 'folder', $labs->id);`,
        `echo json_encode(['url' => "/workspaces/{$w->id}/notes/{$n->id}", 'empty' => "/workspaces/{$w->id}/notes/{$e->id}", 'workspace' => $w->id]);`,
    ].join(' ');
    return JSON.parse(execFileSync(process.env.PHP_BINARY || 'php', ['artisan', 'tinker', '--execute', code], { cwd: appRoot, stdio: 'pipe' }).toString().trim().split('\n').pop());
}

/** `count` new notes in the student's first workspace, each pinned, made through the real services. */
export function pinNewNotes(email, count) {
    const code = [
        `$p = app(\\App\\Identity\\PrincipalFactory::class)->forUser(\\App\\Models\\User::query()->where('email', '${email}')->firstOrFail(), 'web');`,
        `$n = app(\\App\\Study\\Notes::class); $w = app(\\App\\Study\\Workspaces::class)->list($p)[0];`,
        `for ($i = 1; $i <= ${count}; $i++) { $n->pin($p, $n->create($p, 'workspace', $w->id, "Pinned {$i}")->id); }`,
    ].join(' ');
    execFileSync(process.env.PHP_BINARY || 'php', ['artisan', 'tinker', '--execute', code], { cwd: appRoot, stdio: 'pipe' });
}

/** A student with a Databases workspace, one module, three topics in different states, and one open question. */
export function makeStudentWithTopics() {
    const email = makeAccount(false);
    const code = [
        `$p = app(\\App\\Identity\\PrincipalFactory::class)->forUser(\\App\\Models\\User::query()->where('email', '${email}')->firstOrFail(), 'web');`,
        `$w = app(\\App\\Study\\Workspaces::class)->create($p, ['name' => 'Databases', 'colour' => 'blue', 'icon' => 'landmark']);`,
        `$m = app(\\App\\Study\\Modules::class)->create($p, $w->id, ['title' => 'Week 1: Relational model']);`,
        `$t = app(\\App\\Study\\Topics::class); $joins = $t->create($p, $w->id, 'Joins', $m->id); $keys = $t->create($p, $w->id, 'Primary and foreign keys', $m->id); $norm = $t->create($p, $w->id, 'Normalisation');`,
        `$t->report($p, $joins->id, 'understood'); $t->report($p, $keys->id, 'confused');`,
        `app(\\App\\Study\\Questions::class)->ask($p, $w->id, 'Why does a left join keep the unmatched rows?', $joins->id);`,
        `$note = app(\\App\\Study\\Notes::class)->create($p, 'module', $m->id, 'Lecture 3: joins');`,
        `$f = app(\\App\\Study\\Findings::class); $f->add($p, $joins->id, ['text' => 'A left join keeps every row of the left table, matched or not.', 'source' => 'note:'.$note->id, 'locator' => 'slide 12']); $f->add($p, $joins->id, ['text' => 'An inner join keeps only the rows that match on both sides.'], 'ai');`,
        `app(\\App\\Study\\Links::class)->add($p, 'module', $m->id, ['title' => 'Joins explained (video)', 'url' => 'https://www.youtube.com/watch?v=joins']);`,
        `$a = app(\\App\\Study\\Activities::class); $a->create($p, $w->id, ['kind' => 'assignment', 'title' => 'ER diagram for the library', 'due_on' => now()->addDays(2)->toDateString(), 'module_id' => $m->id]); $a->create($p, $w->id, ['kind' => 'exam', 'title' => 'Midterm', 'due_on' => now()->addDays(20)->toDateString()]); $lab = $a->create($p, $w->id, ['kind' => 'lab', 'title' => 'SQL lab 2', 'due_on' => now()->subDay()->toDateString()]); $a->setStatus($p, $lab->id, 'doing');`,
        `app(\\App\\Study\\Instructions::class)->set($p, 'workspace:'.$w->id, 'Go slide by slide. After each section, ask me two questions before moving on.');`,
        `echo json_encode(['workspace' => $w->id]);`,
    ].join(' ');
    const out = execFileSync(process.env.PHP_BINARY || 'php', ['artisan', 'tinker', '--execute', code], { cwd: appRoot, stdio: 'pipe' }).toString().trim().split('\n').pop();
    return { email, ...JSON.parse(out) };
}

/**
 * makeStudentWithTopics(), plus study time: 1 h 30 min logged yesterday,
 * and a session on Joins that started 40 minutes ago (25 min of study, a
 * 5-minute break, and the clock running again for the last 10 minutes).
 */
export function makeStudentWithSession() {
    const student = makeStudentWithTopics();
    const code = [
        `$p = app(\\App\\Identity\\PrincipalFactory::class)->forUser(\\App\\Models\\User::query()->where('email', '${student.email}')->firstOrFail(), 'web');`,
        `$s = app(\\App\\Study\\Sessions::class); $w = '${student.workspace}';`,
        `$joins = collect(app(\\App\\Study\\Topics::class)->list($p, $w))->firstWhere('name', 'Joins');`,
        `$s->log($p, $w, ['date' => now()->subDay()->format('Y-m-d'), 'time' => '14:00', 'minutes' => 90, 'topic_id' => $joins->id], 'UTC');`,
        `$start = now(); \\Illuminate\\Support\\Carbon::setTestNow($start->copy()->subMinutes(40));`,
        `$session = $s->start($p, $w, $joins->id);`,
        `\\Illuminate\\Support\\Carbon::setTestNow($start->copy()->subMinutes(15)); $s->takeBreak($p, $session->id);`,
        `\\Illuminate\\Support\\Carbon::setTestNow($start->copy()->subMinutes(10)); $s->resume($p, $session->id);`,
        `\\Illuminate\\Support\\Carbon::setTestNow();`,
        `echo json_encode(['session' => $session->id]);`,
    ].join(' ');
    const out = execFileSync(process.env.PHP_BINARY || 'php', ['artisan', 'tinker', '--execute', code], { cwd: appRoot, stdio: 'pipe' }).toString().trim().split('\n').pop();
    return { ...student, ...JSON.parse(out) };
}

/**
 * makeStudentWithTopics(), plus a Pomodoro session on Joins (5-minute focus,
 * 1-minute breaks) whose focus period ends in `secondsLeft` seconds.
 */
export function makeStudentWithPomodoro(secondsLeft = 4) {
    const student = makeStudentWithTopics();
    const code = [
        `$p = app(\\App\\Identity\\PrincipalFactory::class)->forUser(\\App\\Models\\User::query()->where('email', '${student.email}')->firstOrFail(), 'web');`,
        `$w = '${student.workspace}'; $joins = collect(app(\\App\\Study\\Topics::class)->list($p, $w))->firstWhere('name', 'Joins');`,
        `\\Illuminate\\Support\\Carbon::setTestNow(now()->subSeconds(${300 - secondsLeft}));`,
        `$session = app(\\App\\Study\\Sessions::class)->start($p, $w, $joins->id, null, ['focus' => 5, 'short' => 1, 'long' => 5, 'every' => 4, 'auto' => true]);`,
        `\\Illuminate\\Support\\Carbon::setTestNow();`,
        `echo json_encode(['session' => $session->id]);`,
    ].join(' ');
    const out = execFileSync(process.env.PHP_BINARY || 'php', ['artisan', 'tinker', '--execute', code], { cwd: appRoot, stdio: 'pipe' }).toString().trim().split('\n').pop();
    return { ...student, ...JSON.parse(out) };
}

/**
 * makeStudentWithCards(), plus what every page holds a menu for: a folder in the module, notes in the module, in
 * that folder and at the top level, a file, more questions in each state, and a session that is open. Returns the
 * IDs the pages need.
 */
export function makeStudentWithEverything() {
    const student = makeStudentWithCards();
    const code = [
        `$p = app(\\App\\Identity\\PrincipalFactory::class)->forUser(\\App\\Models\\User::query()->where('email', '${student.email}')->firstOrFail(), 'web');`,
        `$w = '${student.workspace}'; $m = app(\\App\\Study\\Modules::class)->list($p, $w)[0]; $notes = app(\\App\\Study\\Notes::class);`,
        `$folder = app(\\App\\Study\\Folders::class)->create($p, 'module', $m->id, 'Labs');`,
        `$inModule = $notes->create($p, 'module', $m->id, 'Lecture 3: joins'); $inFolder = $notes->create($p, 'folder', $folder->id, 'Lab 1'); $top = $notes->create($p, 'workspace', $w, 'Exam plan');`,
        `$file = app(\\App\\Study\\Files::class)->upload($p, 'module', $m->id, base_path('tests/Browser/fixtures/files/Lecture 2 - cell division.pdf'), 'Lecture 2.pdf');`,
        `$q = app(\\App\\Study\\Questions::class); $stuck = $q->ask($p, $w, 'Why does a left join keep unmatched rows?', null, $m->id); $q->setStatus($p, $stuck->id, 'stuck');`,
        `$done = $q->ask($p, $w, 'What is a key?', null, $m->id); $q->setStatus($p, $done->id, 'answered', 'A column that names a row.');`,
        `$session = app(\\App\\Study\\Sessions::class)->start($p, $w, null, $m->id);`,
        `echo json_encode(['module' => $m->id, 'folder' => $folder->id, 'note' => $inModule->id, 'topNote' => $top->id, 'file' => $file->id, 'session' => $session->id, 'question' => $stuck->id]);`,
    ].join(' ');
    const out = execFileSync(process.env.PHP_BINARY || 'php', ['artisan', 'tinker', '--execute', code], { cwd: appRoot, stdio: 'pipe' }).toString().trim().split('\n').pop();
    return { ...student, ...JSON.parse(out) };
}

/**
 * A student with Operating Systems (and an empty Biology): a module, its Lectures folder holding a PDF and a
 * Word file, and the note "Kernel summary" there with a picture. Returns the email and the pages' paths.
 */
export function makeStudentWithStudyFiles() {
    const email = makeAccount(false);
    const fixtures = "base_path('tests/Browser/fixtures/files/')";
    const code = [
        `$p = app(\\App\\Identity\\PrincipalFactory::class)->forUser(\\App\\Models\\User::query()->where('email', '${email}')->firstOrFail(), 'web');`,
        `$w = app(\\App\\Study\\Workspaces::class)->create($p, ['name' => 'Operating Systems', 'colour' => 'blue', 'icon' => 'code']);`,
        `app(\\App\\Study\\Workspaces::class)->create($p, ['name' => 'Biology', 'colour' => 'green', 'icon' => 'microscope']);`,
        `$m = app(\\App\\Study\\Modules::class)->create($p, $w->id, ['title' => 'Week 1: Architecture and System Calls']);`,
        `$lectures = app(\\App\\Study\\Folders::class)->create($p, 'module', $m->id, 'Lectures'); $files = app(\\App\\Study\\Files::class);`,
        `$pdf = $files->upload($p, 'folder', $lectures->id, ${fixtures}.'Lecture 2 - cell division.pdf', 'Lecture 01 - Kernel and System Calls with a long name.pdf');`,
        `$docx = $files->upload($p, 'folder', $lectures->id, ${fixtures}.'Essay - why cells divide.docx', 'Syllabus.docx');`,
        `$learner = \\App\\Platform\\Access\\Guard::learner($p)->learnerId;`,
        `\\App\\Study\\Files::disk()->put(\\App\\Study\\NoteImages::key($learner, 'device-pic', 'png'), file_get_contents(${fixtures}.'Onion cells.png'));`,
        `$notes = app(\\App\\Study\\Notes::class); $n = $notes->create($p, 'folder', $lectures->id, 'Kernel summary');`,
        `$notes->save($p, $n->id, ['base_version' => 1, 'save_id' => 'device-save-1', 'client_id' => 'device-tab-1', 'title' => 'Kernel summary', 'doc' => ['type' => 'doc', 'content' => [`,
        `['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'System calls are the doorway into the kernel. '.str_repeat('More words here. ', 20)]]],`,
        `['type' => 'image', 'attrs' => ['src' => '/notes/images/device-pic', 'alt' => 'Onion cells', 'width' => '60%', 'align' => 'center']],`,
        `['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'After the picture.']]]]]]);`,
        `echo json_encode(['folder' => route('workspaces.folders.show', [$w->id, $lectures->id], false), 'pdf' => route('workspaces.files.show', [$w->id, $pdf->id], false),`,
        `'docx' => route('workspaces.files.show', [$w->id, $docx->id], false), 'note' => route('workspaces.notes.show', [$w->id, $n->id], false),`,
        `'window' => route('workspaces.notes.show', [$w->id, $n->id, 'window' => 1], false)]);`,
    ].join(' ');
    const out = execFileSync(process.env.PHP_BINARY || 'php', ['artisan', 'tinker', '--execute', code], { cwd: appRoot, stdio: 'pipe' }).toString().trim().split('\n').pop();
    return { email, ...JSON.parse(out) };
}

/**
 * makeStudentWithTopics(), plus flashcards: three on Joins (one made with an
 * AI), one on keys, and one on keys already answered, due tomorrow.
 */
export function makeStudentWithCards() {
    const student = makeStudentWithTopics();
    const code = [
        `$p = app(\\App\\Identity\\PrincipalFactory::class)->forUser(\\App\\Models\\User::query()->where('email', '${student.email}')->firstOrFail(), 'web');`,
        `$w = '${student.workspace}'; $topics = collect(app(\\App\\Study\\Topics::class)->list($p, $w))->keyBy('name'); $c = app(\\App\\Study\\Flashcards::class);`,
        `$joins = $topics['Joins']->id; $keys = $topics['Primary and foreign keys']->id;`,
        `$c->add($p, $w, $joins, 'What does a LEFT JOIN keep?', 'Every row of the left table, with NULLs where the right table has no match.');`,
        `$c->add($p, $w, $joins, 'What does an INNER JOIN keep?', 'Only the rows that match on both sides.');`,
        `$c->add($p, $w, $joins, 'When would you use a FULL OUTER JOIN?', 'To keep every row of both tables, matched or not.', 'ai');`,
        `$c->add($p, $w, $keys, 'What makes a column a foreign key?', 'It refers to the primary key of another table.');`,
        `$done = $c->add($p, $w, $keys, 'What must a primary key be?', 'Unique and never empty.'); $c->answer($p, $done, 'correct');`,
        `echo json_encode(['cards' => 4]);`,
    ].join(' ');
    const out = execFileSync(process.env.PHP_BINARY || 'php', ['artisan', 'tinker', '--execute', code], { cwd: appRoot, stdio: 'pipe' }).toString().trim().split('\n').pop();
    return { ...student, ...JSON.parse(out) };
}

/** makeStudentWithCards, with a second module holding one card on a topic of its own, and one card in no module. */
export function makeStudentWithModuleCards() {
    const student = makeStudentWithCards();
    const code = [
        `$p = app(\\App\\Identity\\PrincipalFactory::class)->forUser(\\App\\Models\\User::query()->where('email', '${student.email}')->firstOrFail(), 'web');`,
        `$w = '${student.workspace}'; $c = app(\\App\\Study\\Flashcards::class);`,
        `$m = app(\\App\\Study\\Modules::class)->create($p, $w, ['title' => 'Week 2: SQL queries']);`,
        `$t = app(\\App\\Study\\Topics::class)->create($p, $w, 'Subqueries', $m->id);`,
        `$c->add($p, $w, $t->id, 'What does a correlated subquery refer to?', 'A column of the outer query.');`,
        `$c->add($p, $w, null, 'What does SQL stand for?', 'Structured Query Language.');`,
        `echo json_encode(['week2' => $m->id]);`,
    ].join(' ');
    const out = execFileSync(process.env.PHP_BINARY || 'php', ['artisan', 'tinker', '--execute', code], { cwd: appRoot, stdio: 'pipe' }).toString().trim().split('\n').pop();
    return { ...student, ...JSON.parse(out) };
}

/** A student with no second factor, so login finishes on the home page. */
export function makeStudentAccount() {
    return makeAccount(false);
}

/** Log in as a confirmed two-factor account and wait on the challenge screen. */
export async function loginToChallenge(page, email = makeTwoFactorAccount()) {
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password', { exact: true }).fill('password-for-tests');
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL('**/two-factor-challenge');

    return email;
}

/** Sign in as a student and open the password confirmation screen. */
export async function openConfirmPassword(page, email = makeStudentAccount()) {
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password', { exact: true }).fill('password-for-tests');
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.getByRole('heading', { name: 'My courses' }).waitFor();
    await page.goto('/user/confirm-password');
    await page.waitForURL('**/user/confirm-password');

    return email;
}

/** The current 6-digit code for a base32 setup key (RFC 6238, as authenticator apps compute it). */
export function totp(setupKey, now = Date.now()) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const char of setupKey.replace(/\s+/g, '').toUpperCase()) {
        bits += alphabet.indexOf(char).toString(2).padStart(5, '0');
    }
    const key = Buffer.from(bits.match(/.{8}/g).map((byte) => parseInt(byte, 2)));
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(now / 30000)));
    const hmac = createHmac('sha1', key).update(counter).digest();
    const offset = hmac[hmac.length - 1] & 0xf;
    const value = (hmac.readUInt32BE(offset) & 0x7fffffff) % 1000000;

    return String(value).padStart(6, '0');
}

/** Sign in as a new student and open the two-factor setup, confirming the password on the way. */
export async function openTwoFactorSetup(page, email = makeStudentAccount()) {
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password', { exact: true }).fill('password-for-tests');
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.getByRole('heading', { name: 'My courses' }).waitFor();
    // The account menu works once the page's script has run.
    await page.waitForLoadState('load');
    await page.locator('[data-menu-button]').click();
    await page.getByRole('link', { name: 'Security' }).click();
    await page.waitForURL('**/user/confirm-password');
    await page.getByLabel('Password', { exact: true }).fill('password-for-tests');
    await page.getByRole('button', { name: 'Confirm' }).click();
    await page.waitForURL('**/user/two-factor');

    return email;
}

/** From the setup screen's "off" state, turn it on and wait for the QR code. */
export async function startTwoFactorSetup(page) {
    await page.getByRole('button', { name: 'Turn on two-factor authentication' }).click();
    await page.getByRole('heading', { name: 'Set up your authenticator app' }).waitFor();
    await page.waitForLoadState('load');

    return (await page.locator('#setup-key').textContent()).trim();
}

/** Log in as a new admin with 2FA (using a recovery code), confirm the password, and open the admin overview. */
export async function openAdminOverview(page) {
    const email = makeAccount(true, true);
    await loginToChallenge(page, email);
    await page.getByRole('button', { name: 'Use a recovery code instead' }).click();
    await page.getByLabel('Recovery code').fill('code-one-aaaa');
    await page.getByRole('button', { name: 'Verify' }).click();
    await page.getByRole('heading', { name: 'My courses' }).waitFor();
    // The account menu works once the page's script has run.
    await page.waitForLoadState('load');
    await page.locator('[data-menu-button]').click();
    await page.getByRole('link', { name: 'Admin area' }).click();
    await page.waitForURL('**/user/confirm-password');
    await page.getByLabel('Password', { exact: true }).fill('password-for-tests');
    await page.getByRole('button', { name: 'Confirm' }).click();
    await page.waitForURL('**/admin');

    return email;
}

/** Log in as a new student and wait on the home page, inside the signed-in frame. */
export async function openStudentHome(page, email = makeStudentAccount()) {
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password', { exact: true }).fill('password-for-tests');
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.getByRole('heading', { name: 'My courses' }).waitFor();
    await page.waitForLoadState('load');

    return email;
}

/** Log in as a new admin, open the Accounts page, and search for $email if given. Returns the admin's email. */
export async function openAccounts(page, email = null) {
    const admin = await openAdminOverview(page);
    await page.goto('/admin/accounts');
    await page.getByRole('heading', { name: 'Accounts', exact: true }).waitFor();
    await page.waitForLoadState('load');
    if (email) {
        await page.getByLabel('Search accounts').fill(email);
        await page.getByText('1 match', { exact: true }).waitFor();
    }

    return admin;
}
