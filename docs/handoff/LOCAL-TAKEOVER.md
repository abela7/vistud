# Local takeover

- **Written:** 2026-09-25, by the architect, at the end of the cloud development sessions.
- **For:** the local team (Grok and Gemini) and the PM.
- **Status:** a handover, not an assignment. The PM assigns packages. Nothing here says the local Windows environment works: [§9](#9-what-was-verified-where) says exactly what was checked, where, and what the local team still has to verify.

Read [STATUS.md](../coordination/STATUS.md) with this page. STATUS.md is the live record; this page explains the state at handover and how to pick the work up.

## 1. Read this first

**Recommended starting point: the `m1/wp6-web-adapters` branch, at its latest pushed commit.** It contains everything on the integration branch (including DOC1 and this handover) plus the WP6 visual foundation and the login screen, so it is the most complete tree that runs in a browser. It does **not** contain WP4's projection work, which stays on its own branch.

- To continue **WP6**, work on `m1/wp6-web-adapters` (its pull request is still open).
- To start **any other package**, branch from the integration branch, `claude/persistent-study-context-zsilo6`.
- To change **WP4**, work on `m1/wp4-projection`.
- **Don't merge WP4 or WP6** into the integration branch until the PM accepts them.

## 2. Branches, pull requests and commits

| Branch | Pull request | State | Pushed commits (oldest first) |
|---|---|---|---|
| `claude/persistent-study-context-zsilo6` (integration) | — | Integration branch | `27ce003` last implementation commit (M1 increment 3) · `242317c` onboarding documents · `a154850` PM decisions, WP4 started · `f70abd0` **DOC1 merged** · then this handover (STATUS, setup wording, this page), documentation only |
| `m1/wp4-projection` | [#2](https://github.com/abela7/vistud/pull/2), open | **Delivered, awaiting review.** Additive contracts approved; independent acceptance (V1) outstanding | `10b98a0` rules@1 and developer tests · `df2cfaa` STATUS link · `caa2c28` PM rulings, incomplete splits never take effect · `dc55126` STATUS People row left alone for DOC1 · then a STATUS-only commit at handover |
| `m1/wp6-web-adapters` | [#3](https://github.com/abela7/vistud/pull/3), open | **In progress.** Visual direction approved for continuation; not accepted | `7ffab31` brand assets and themes · `a4eeb4f` front-end foundation and login · `9e629da` DESIGN.md, previews, documents · `8ac9f03` STATUS link (**the commit the PM reviewed**) · then, at handover, a merge of the integration branch (DOC1 and this page) and a commit recording the PM's design-review decisions in DESIGN.md and ADR 0003 (documentation only) |
| `doc1/setup-docs` | [#1](https://github.com/abela7/vistud/pull/1), merged | **Accepted** (Grok) | `f25d7d6`, `35eb490`, `362b090`; merged as `f70abd0` |

`git log --oneline -5 origin/<branch>` shows each branch's current head.

## 3. What works, and what is only a preview or incomplete

**Works end to end** (automated tests, and for the browser rows a manual run in the cloud):

| Capability | Branch | How it is shown to work |
|---|---|---|
| Two MySQL users; migrations as the owner; per-table grants; the audit log append-only at the database level | all | `tests/Feature/Platform/*` |
| Accounts from the console: create, grant admin, reset 2FA; the synthetic seeder | all | `tests/Feature/Identity/ConsoleCommandsTest.php`; seeding run in the cloud |
| Login, logout, roles, the admin middleware, the last-admin safeguard, invitations, 2FA and recovery codes **as backend endpoints** | all | `tests/Feature/Identity/*` (JSON and HTTP, no screens) |
| JSON API: `GET /api/v1/me`, `GET /api/v1/journal/entries/{id}`, on the web session | all | `tests/Feature/Api/*`, OpenAPI contract test |
| Journal writer and store, including concurrent appends (Unix) | all | `tests/Feature/Brain/Writer/*`, `Store/*` |
| Projection under `rules@1`, the golden replay reproduced by developer tests | **WP4 only** | 106 developer tests in `tests/Unit/Brain/Projection/`. The integration branch still has the untested draft |
| Themes as data, contrast checked across whole gradients, `vistud:themes:build`, the three enforcement layers | **WP6 only** | `tests/Unit/Appearance/*`, `tests/Architecture/ThemeEnforcementTest.php`, `tests/Browser/theme-sentinel.spec.js` |
| **Browser login:** `/` → `/login` → log in as a seeded student → placeholder home → log out | **WP6 only** | `tests/Feature/Web/LoginScreenTest.php`; run by hand in the cloud with Playwright |
| Light, dark and system appearance, switched without a reload | **WP6 only** | `tests/Browser/theme-switch.spec.js` |

**Preview only, or incomplete:**

| Item | Reality |
|---|---|
| `docs/design/previews/*` | Screenshots and a recording for review. They are generated, not a running feature |
| Home after login | **My workspaces** (M2 step 1): create a workspace (name, colour, icon, optional code, term and dates), open it (Overview, and "coming next" pages for Modules, Notes & files, Flashcards, Calendar and Progress), switch between workspaces from the sidebar, edit, archive and restore. **Modules** (M2 step 2): add, edit, reorder (drag the handle, or Move up/down in the ⋯ menu) and delete empty modules; folders inside them, up to 8 levels, with rename, move and delete. **Notes** (M2 step 3a): New note in a module, a folder or Notes & files opens the editor; it saves by itself and says where your text is (Saved, Saved on this device, retrying). Trash and restore in Notes & files. A note fills the screen, with **Full screen** and **Read** buttons. Two tabs of one note keep each other up to date; logging out with unsaved changes asks to save, keep or discard them. **Files** (M2 step 4): Upload files in a module, a folder's menu or Notes & files; PDF, images and text preview on the file's page, the rest download. After pulling, run `composer migrate` (the `files` table). **Raise PHP's upload limit** in php.ini (`upload_max_filesize = 25M`, `post_max_size = 30M`) and restart, or uploads stop at PHP's default of 2 MB; the upload dialog shows the limit in force. Office files need PHP's `zip` extension (on in Laragon and XAMPP). **Progress** (the tracker, docs/specs/study-memory.md): add topics (per module), press Covered / Understood / Confused on each and read the evidence line, register questions and mark them understood or for the teacher; open a topic's findings and add "must know" lines with their source. **Overview**: where you are (topics by status, what's confusing, open questions), assignments and tasks (tick them off, soonest due first), notes to pick up, and instructions for the assistant (about you, this course; a module's in its menu). **Links**: Add link in a module, a folder's menu or Notes & files. **Study sessions**: Start studying on the Overview (or Study this now in a topic's menu in Progress); the clock shows in the top bar on every page; Pause, Take a break, Back to studying, End session (and say how the topic is now). Leave it alone for 30 minutes and it pauses itself; the session page offers to count that time. Log time on the Overview adds time studied without the clock. **Pomodoro**: choose Pomodoro when starting (Classic 25/5/15, Deep work 50/10/30, Short bursts 15/3/10, or custom), or switch from the session's ⋯ menu; the ring counts down, a chime plays when a phase ends (Sound turns it off), and Notify me asks the browser for notifications. **Briefing**: choose how the AI teaches when you start (Change under "How the AI teaches"), or later from the session page; press Use on the notes and files the AI should get; Briefing shows exactly what the AI receives, with Copy and Download. Paste it into any AI you use to start the tutor warm. The prompt itself is `resources/prompts/tutor.md`: edit it freely. **Save from the chat**: after studying with the AI, copy its replies (or the whole chat) and paste them on the session page; tick what to keep (statuses only if you agree), and ViStud saves the key points, questions, flashcards, your answers (as evidence), the summary and where it stopped, plus a session note in the module. After pulling, run `composer migrate` (the write-back columns and `flashcards`). **Flashcards**: the Flashcards section (Cards on a phone) shows what's due; New card writes one (Save and add another for several), Make cards with an AI gives a prompt to copy into any AI and reads its pasted reply, and Review now goes through the due cards: Space turns a card over, 1, 2 or 3 answers (Not yet, Partly, Got it), and each card comes back later the better you know it. New flashcard is also in a topic's ⋯ menu in Progress and in a session's ⋯ menu; the Overview shows how many are due. After pulling, run `composer migrate` (the review columns on `flashcards`), `npm run build`. The card-making prompt is `resources/prompts/flashcards.md`: edit it freely. **Redesign after the owner's review (2026-09-27)**: less text everywhere; the Overview is a greeting, Start studying, the streak, the last 7 days, cards to review, Continue and Coming up (edit, AI instructions and Log time are in its ⋯ menu); Modules are cards, and a module or a folder opens as its own page with **New** (note, files, link, folder) and **Study this**. No migration; run `npm run build`. **One session at a time**: starting another while one is open (even paused) shows it, with Go to the session and End it; the database refuses a second one. A session in a module sits under Modules; the top-bar timer has Hide the timer (a pulsing dot shows it again). In a session, Notes & files holds only that module's notes and files, by folder, with a search. Each module has a **Questions page** (Questions on the module's page; New → Question), with a search, the status filter and a sort; in a session the questions are folded until opened. **New note** opens an empty editor and keeps nothing until you write a title or some text. The **note editor** has a text-style menu, underline, strikethrough, highlight colours, superscript and subscript, alignment, tables, links, clear formatting and a word count (after pulling, `npm install` for the new editor packages). Gemini's additions to it: callouts, a symbols palette, pictures (Add a picture, or paste or drop one; PNG, JPEG, WebP or GIF up to 10 MB), page breaks (Ctrl+Enter) with A4 pages or full width, find and replace (Ctrl+F, Ctrl+H), Word's shortcuts (Ctrl+/ lists them), statistics (click the word count) and Print. No migration; run `npm install` and `npm run build`. **Back**: every page but Home has a Back button above its title, naming the page above it (a note → its folder → its module → Modules → Overview → All workspaces). Coming from that page, Back returns to it scrolled where you left it. On a phone it replaces the path line. No migration; run `npm run build`. **Modules as a list** (Gemini's, merged 2026-09-28): Grid / List above the modules switches between cards and compact rows; the choice is remembered in this browser. No migration; run `npm run build`. **Study sessions page** (Gemini's, merged 2026-09-28): a module's page has Study sessions (with how many) next to Questions; it opens every session of the module with the time studied, a search, All / Studying / Ended and a sort. No migration; run `npm run build`. After pulling, run `composer migrate` (`open_learner` on `study_sessions`, `create_id` on `notes`), `npm install` and `npm run build`. **Questions**: on a module's page, in a study session and in Progress, write a question in one line; each is Pending, Stuck or Answered (⋯ on its row), and opening one shows a side panel to change it and write the answer. Every dialog now opens as a panel on the right. After pulling, run `composer migrate` (the question columns) and `npm run build`. **Study session, an open space**: a slim clock bar, then tiles for what to do (Study with an AI, Save from the chat, Ask a question, New flashcard, Write a note, Notes & files); questions show only once there is one; How the AI teaches and Pomodoro settings are in its ⋯ menu; a module's New has Question. **Notifications** pop up at the bottom and close themselves after a few seconds (× closes them sooner). No migration; run `npm run build`. Run `php artisan schedule:work` to have forgotten sessions settle on their own (optional locally: opening ViStud settles them too). After pulling, run `composer migrate` (`study_sessions`, `session_segments`) and `npm run build`. After pulling, run `composer migrate` (the `topics`, `questions`, `findings`, `links`, `activities` and `instructions` tables). After pulling, run `npm install` (the editor, Tiptap), `composer migrate` (new tables) and `npm run build`. Trashed notes are deleted after 30 days by `php artisan schedule:work` (optional locally) |
| Admin workspace in a browser | Works (WP6 branch): an admin with 2FA opens it from the home page (a fresh password is asked on entry) and lands on the admin overview in the same frame, with the Admin marker in the top bar and a switch back to the student area in the account menu. The Accounts page (`/admin/accounts`, Livewire) lists and searches accounts, and suspends, reactivates, grants or removes admin, and resets 2FA, each confirmed in a dialog, without a page reload. The Audit log page (`/admin/audit-log`) is a read-only list, newest first, each entry a sentence (who, what, to whom, when), filtered by action. Any other `/admin` URL answers 404 |
| Two-factor authentication | Works end to end (WP6 branch): turn it on from the home page (`/user/two-factor`, after a password check), scan the QR code, confirm a code, save the recovery codes (shown once), then log in with a code or a recovery code |
| Invitations | Works (WP6 branch): **Invite someone** on the Accounts page makes a single-use link, valid 72 hours, shown once for the admin to share; pending invitations can be cancelled. The link (`/invitation#<token>`) opens a page where the person chooses a name and password, then lands on home logged in as a student. No email is sent (Q3) |
| Password reset, password confirmation | Fortify's POST endpoints only. The "Forgot password?" link appears by itself once a `password.request` route exists |
| Error pages | Laravel's default, unstyled 403 and 404 pages |
| Appearance preference | Kept in the browser only. Account storage is M2 |
| Custom themes from seed colours | M2. DESIGN.md §3.7 records the rule |

## 4. What is left in WP6

In this order; each step is small and reviewable. Every screen gets the DESIGN.md §10 checks (screenshots, keyboard, sentinel test in all its states, axe, touch targets, long content) and a PM visual review before the next one is styled. Who does this is the PM's decision.

0. **Deferred to the polish phase (owner's decision):** apply the PM's logo decision. Show the white logo directly on dark and gradient surfaces, and keep the full-colour logo on light surfaces. Today the code shows the full-colour logo on a light plate on dark surfaces. The change touches:
   - the `<x-logo>` component and `.logo-plate` in `resources/css/components.css`;
   - the `logo-plate` token in `resources/themes/*.json`, `App\Appearance\Theme::COLORS` and `app.css`;
   - the logo check in `ThemeValidator` (it becomes the white logo against dark surfaces) and its test;
   - DESIGN.md §2.3, ADR 0003 §6.1 and §6.4, and contracts.md;
   - the logo-treatments preview.

   The component can't see the theme, so the choice needs a theme-driven switch, for example CSS generated per dark theme by `Themes::stylesheet()`. Rebuild the themes and previews afterwards.
1. **Done:** ~~Two-factor challenge screen:~~ `GET /two-factor-challenge`, named `two-factor.login`. Fortify's POST (`two-factor.login.store`) exists.
2. **Done:** ~~Password confirmation screen:~~ named `password.confirm`, which `App\Platform\Http\WebErrors` already redirects to once it exists. Fortify's POST (`password.confirm.store`) exists.
3. **Done:** ~~Two-factor setup screen:~~ named `two-factor.setup` (`WebErrors::TWO_FACTOR_SETUP_ROUTE`). It covers enable, QR code, confirm, and recovery codes shown once. The endpoints exist under `/user/two-factor-*`, and each needs a recent password confirmation (step 2).
4. **Forgot and reset password screens:** `password.request` and `password.reset`. The POSTs exist. Locally, mail should go to the log.
5. **Done:** ~~Invitation acceptance page~~ (`GET /invitation`, `resources/views/auth/accept-invitation.blade.php`, `resources/js/invitation.js`) and the admin side (`App\Livewire\Admin\Accounts\Invitations`). Link format: `/invitation#<token>`, so the token never reaches a server log.
6. **Done:** ~~Install Livewire 4.4.6~~ with the Accounts page. `config/livewire.php` points the navigation progress bar at `var(--accent)`, and `AppServiceProvider` registers the admin checks (`role`, `two_factor`, `admin.workspace`) as Livewire persistent middleware, so a Livewire action re-runs its page's checks.
7. **Done:** ~~Workspace switch and admin landing page~~ (`routes/web/admin-screens.php`, `resources/views/admin/overview.blade.php`, `<x-layouts.app area="admin">`, `<x-admin.marker>`).
8. **Livewire surfaces for the security tests (V2)**, each a thin adapter over a service, with `#[Locked]` IDs:
   - `Admin\Accounts\Index` (**done**: list and search; suspend, reactivate, grant and revoke admin, reset 2FA; `selectedId` is `#[Locked]`)
   - `Admin\AuditLog\Index` (**done**: read-only, newest first, one action filter; it has no action that writes)
   - `Journal\EntryShow` (**done**: `/journal/{entry}` with a `#[Locked]` ID, under a `/journal` list; another learner's entry, a tampered locked ID and an edited snapshot all answer 404 like a missing entry. `php artisan vistud:journal:sample {email}` fills an empty journal for trying it)
9. **403 and 404 pages** on the shared layout.

**M2, in progress (approved):** [docs/specs/workspaces.md](../specs/workspaces.md), one workspace per subject with Overview, Modules, Notes & files, Calendar and Progress. Mockups in `docs/design/mockups/`, and on a development machine at `/_mockups/workspaces` (local only; regenerate with `MOCKUPS=1 npx playwright test mockups`).

**Backend dependencies:**
- **Services these screens call:** WP2's services (`Accounts`, `Roles`, `Invitations`, `TwoFactorReset`, `Workspaces`, the audit log) and WP3's `JournalReader`. Both are delivered but not yet reviewed, and nothing else is needed from the backend for these screens.
- **Fortify's views** stay off (`config/fortify.php`). Register each screen's GET route yourself in `routes/web/auth.php`. Turning views on registers every Fortify GET route at once, so do it only when every view is bound.

## 5. Known issues

- **Reset emails have no rate limit per address or IP.** Fortify's `POST /forgot-password` has no throttle; the password broker only stops a second email to the same address within 60 seconds. Before a live server sends real mail, add a limiter on that route.

- **The logo decision is not implemented yet** (§4, step 0).
- **The concurrency test needs Unix** `pcntl` and `posix`. On Windows it is skipped, and a skip does not satisfy the M1 gate. CI runs it.
- **Windows setup is partly verified** (§9): PHP 8.4, MySQL 8.4, the build and the PHP tests run on the owner's machine. Still unverified:
  - The database-user step is documented for Unix shells only; PowerShell rejects `<`.
  - XAMPP's PHP 8.0 and MariaDB 10.4 are below the baseline. MariaDB's `REVOKE` also lacks the clauses our grants statement uses (DOC1 checked this against the documentation; it was not executed).
  - See DOC1's notes in [setup.md](../development/setup.md).
- **`composer.json` allows PHP `^8.3`,** but only 8.4 is supported and tested.
- **This cloud machine ran MySQL 8.0.46,** not 8.4. CI runs 8.4.
- **The compiled-CSS theme scan skips** when `public/build/` is missing. Build first (CI always does).
- **Tailwind only reads views and scripts** (`resources/views`, `resources/js`). Classes written anywhere else are never generated.
- **Preview regeneration needs** the app running on port 8000 with a migrated database, and Playwright's ffmpeg for the recording.
- **WP2 and WP3 contracts stay Provisional** until the PM reviews increments 2 and 3.

## 6. File ownership

Each package's files are listed in [m1-work-packages.md](m1-work-packages.md), and the shared files in [modules.md](../architecture/modules.md#shared-files). In short:

| Package | Owner | Owns |
|---|---|---|
| WP1 Foundations | Architect | `app/Platform/**`, migrations, database scripts, the architecture, development and handoff documents, `tests/Architecture/**` |
| WP2 Identity | Architect | `app/Identity/**`, `app/Audit/**`, the admin routes and middleware, the account commands, the Fortify backend |
| WP3 Journal | Architect | `app/Brain/{Journal,Store,Writer}/**`, the journal entry endpoint |
| WP4 Projection | Architect | `app/Brain/Projection/**`, `tests/Unit/Brain/Projection/**` |
| WP5 Redaction | Unassigned | `app/Brain/Redaction/**`, the outbox and files |
| WP6 Web adapters | Architect (paused) | `app/Livewire/**`, `resources/views/**`, `routes/web/{auth,student,admin-screens}.php`, `tests/Feature/Web/**`, its screens' browser tests |
| V1, V2 | Unassigned | `tests/Acceptance/Brain/**`, `tests/Acceptance/Fixtures/**`, `tests/Acceptance/Security/**` |
| DOC1 | Grok | Accepted and merged |

**Shared files that need coordination** (architect-owned; propose changes in your pull request or a `contract-change` issue):
- dependencies: `composer.json`, `composer.lock`, `package.json`, `package-lock.json`;
- configuration: `config/*`, `bootstrap/app.php`, `phpunit.xml`, `.env.example`, `vite.config.js`, `playwright.config.js`;
- migrations and routes: `database/migrations/*`, `routes/web.php`, `routes/api/v1.php`;
- CI: `.github/workflows/*`;
- the visual foundation: `DESIGN.md` (changes go through the PM), `resources/themes/*`, `resources/brand/*`, `public/brand/*`, `app/Appearance/**`, `resources/css/**`, `resources/js/**`, `resources/icons/*`, `scripts/*`;
- documents: `docs/architecture/*`, `docs/handoff/*`, the ADRs.

## 7. Setting up a machine

**Requirements:**

| Tool | Version |
|---|---|
| PHP | 8.4, with `pdo_mysql`, `mbstring`, `intl`, `sodium`, `zip`, and `gd` for the tests (and `pcntl`, `posix` on Unix for the concurrency test) |
| Composer | 2.x |
| MySQL | 8.4 LTS. Not MariaDB |
| Node | 22 LTS, with npm 10. Needed on the WP6 branch only |
| Git | Any recent version |

**Steps.** These are Unix shell (bash) commands, as verified in the cloud. Step 1 is Unix-only as written. The other steps are the same commands in any shell, but none has been run on Windows.

```bash
git clone https://github.com/abela7/vistud.git vistud
cd vistud
git checkout m1/wp6-web-adapters

# 1. Databases and users, once per machine, as a MySQL administrator (Unix shell only)
sudo mysql < database/scripts/local-mysql-users.sql

# 2. PHP dependencies, .env from .env.example, the app key, and migrations as the schema owner
composer setup

# 3. Front-end dependencies and the built assets (WP6 branch)
npm ci
npm run build

# 4. Synthetic accounts. Local only: this rebuilds the local database
php artisan migrate:fresh --database=mysql_owner --seed

# 5. Start
php artisan serve        # then open http://127.0.0.1:8000/login
npm run dev              # optional, second terminal: rebuilds assets as you edit
```

**Synthetic accounts** from the seeder:

| Account | Roles |
|---|---|
| `owner@vistud.test` | Student and admin, no 2FA |
| `student.a@vistud.test` | Student |
| `student.b@vistud.test` | Student |

The password for all three is `local-password-only`. It is a local development value, never to be reused. To create an account the way a real installation does, see the "Local accounts" section of [setup.md](../development/setup.md).

**Always migrate as the owner:** `composer migrate`, or `php artisan migrate --database=mysql_owner`. Plain `php artisan migrate` fails by design.

## 8. Environment variables

`.env` is created from `.env.example` by `composer setup` and is ignored by git; never commit it. No secrets live in the repository. The database passwords in `.env.example` and `database/scripts/local-mysql-users.sql` are local-only values.

| Purpose | Names |
|---|---|
| Application | `APP_NAME`, `APP_ENV`, `APP_KEY` (generated by `php artisan key:generate`), `APP_DEBUG`, `APP_URL`, `APP_LOCALE`, `APP_FALLBACK_LOCALE`, `APP_FAKER_LOCALE`, `APP_MAINTENANCE_DRIVER`, `BCRYPT_ROUNDS` |
| Database | `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` (runtime user), `DB_OWNER_USERNAME` and `DB_OWNER_PASSWORD` (schema owner), `DB_RUNTIME_HOSTS` |
| Sessions, cache, queue | `SESSION_DRIVER`, `SESSION_LIFETIME`, `SESSION_ENCRYPT`, `SESSION_PATH`, `SESSION_DOMAIN`, `CACHE_STORE`, `QUEUE_CONNECTION`, `BROADCAST_CONNECTION`, `FILESYSTEM_DISK` |
| Logging, mail, others from the Laravel template | `LOG_CHANNEL`, `LOG_STACK`, `LOG_DEPRECATIONS_CHANNEL`, `LOG_LEVEL`, `MAIL_MAILER`, `MAIL_SCHEME`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`, `MEMCACHED_HOST`, `REDIS_CLIENT`, `REDIS_HOST`, `REDIS_PASSWORD`, `REDIS_PORT`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_USE_PATH_STYLE_ENDPOINT`, `VITE_APP_NAME` |
| Tests | `phpunit.xml` sets the test values (`DB_DATABASE=vistud_test`, the array session and cache). Real environment variables take precedence |
| Browser tests (WP6) | `PLAYWRIGHT_CHROMIUM_PATH` (use an installed Chromium instead of Playwright's download), `BROWSER_TEST_URL` (default `http://127.0.0.1:8000`), `PREVIEWS=1` (regenerate the previews), `CI` |

## 9. What was verified where

The cloud check (2026-09-25) was a fresh clone of `m1/wp6-web-adapters` at `8ac9f03` in an Ubuntu 24.04 container. The handover merge after it adds documentation only. The Windows column is the local team's check on the owner's machine (2026-09-27, Gemini), with its own PHP, MySQL and Node in the ignored `.tools/` folder; rows it didn't cover still say so.

| Step | Cloud container | GitHub CI (ubuntu-24.04) | Windows |
|---|---|---|---|
| PHP 8.4 | 8.4.19 | 8.4 | 8.4.26 (NTS, x64), from `.tools/` (2026-09-27). php.ini needed `extension=zip`, `extension=gd` (the file tests make images) and the upload limits |
| MySQL | **8.0.46**, not 8.4 | 8.4 | 8.4.11, from `.tools/`, on port 3307 (`DB_PORT=3307` in `.env`). XAMPP's MariaDB 10.4 doesn't qualify (DOC1) |
| Node and npm | 22.22.2 and 10.9.7 | Node 22 | 22.23.3 and 10.9.9. PowerShell needed `Set-ExecutionPolicy RemoteSigned -Scope CurrentUser` to run npm |
| Database-user script | Ran (as root, `mysql < …`) | Ran | **No verified procedure** |
| `composer setup` | Ran, with two workarounds specific to this container:<br>• `COMPOSER_ALLOW_SUPERUSER=1`, because it runs as root<br>• `COMPOSER_PROCESS_TIMEOUT=0`, because downloads through its proxy are slow. The first attempt timed out at 300 s | `composer install` ran | Not verified |
| `npm ci`, `npm run build` | Ran | Ran | `npm run build` ran |
| `vistud:themes:build --check` | Passed | Passed (Architecture suite) | Not verified |
| `migrate:fresh --seed` | Ran; three accounts | — | Not verified |
| `composer lint`, `composer test` | Passed, 145 tests | Passed | Passed at `35679ec` plus the Windows path fix `06d3e08`: 370 passed, the concurrency test skipped (no `pcntl` on Windows) |
| Browser tests | 14 passed, using the container's Chromium (`PLAYWRIGHT_CHROMIUM_PATH`). `npx playwright install` was not run | 14 passed after `npx playwright install --with-deps chromium` | Not verified |
| Browser login, end to end | The seeded student logged in, saw the home placeholder and logged out; the seeded admin got 403 at `/admin` | — | Not verified |
| `npm run dev` | Not run | — | Not verified |
| Preview regeneration | Ran | — | Not verified |

## 10. Tests and CI

**Commands:**

```bash
composer lint                                # Pint, code style
composer test                                # every PHP suite, including Acceptance
php artisan test --testsuite=Unit            # or Feature, Architecture
php artisan vistud:themes:build --check      # WP6: theme contrast, generated files up to date
npx playwright install chromium              # once, WP6 browser tests
npm run test:browser                         # WP6: sentinel, theme switching, accessibility
PREVIEWS=1 npx playwright test previews      # WP6: regenerate docs/design/previews/
```

**Latest results:**

| Branch and commit | PHP | Browser | CI |
|---|---|---|---|
| Integration `f70abd0` | 117 passing (Unit 22, Feature 91, Architecture 4), run locally at handover | — | [run 36194418329](https://github.com/abela7/vistud/actions/runs/36194418329) (push) |
| WP4 `dc55126` | 225 passing (Unit 128, Feature 92, Architecture 5) | — | [run 36188952981](https://github.com/abela7/vistud/actions/runs/36188952981) (push) and [run 36188958390](https://github.com/abela7/vistud/actions/runs/36188958390) (pull request) passed |
| WP6 `8ac9f03` | 145 passing (Unit 43, Feature 95, Architecture 7) | 14 passing | [run 36193910486](https://github.com/abela7/vistud/actions/runs/36193910486) (push) and [run 36193914240](https://github.com/abela7/vistud/actions/runs/36193914240) (pull request) passed, both the `test` and `browser` jobs |

The handover commits trigger new runs; see each pull request's checks.

**Skipped and non-blocking checks:**
- **CI's Acceptance step** is skipped because `tests/Acceptance/` has no tests yet. Once tests exist, it runs without failing the build (`continue-on-error`) until the PM declares the M1 gate.
- **`ConcurrentAppendTest`** skips where `pcntl` is missing (Windows). It runs in CI.
- **The compiled-CSS scan** in `ThemeEnforcementTest` skips when there is no build. CI builds first.
- **The 11 preview generators** in `tests/Browser/previews.spec.js` skip unless `PREVIEWS=1`.

## 11. Brand assets, themes and previews

**Logo:**
- `resources/brand/vistud-logo-original.png` is the owner's original, kept byte for byte. Never edit it. Its checkerboard is painted into the pixels.
- `php resources/brand/clean-logo.php` regenerates the four transparent assets in `public/brand/` from it (full colour, white, mark, and white mark), plus the 96 px display copies, the favicon and the Apple touch icon that pages actually load.
- The measured colours are in `resources/brand/logo-colours.json` and DESIGN.md §2.2.

**Themes:**
- The theme data is `resources/themes/*.json`: `vistud-light`, `vistud-dark`, and `ember` (the third built-in theme, by PM decision).
- After editing a theme, run `php artisan vistud:themes:build`. It checks contrast and rewrites `resources/css/themes/themes.css` and `tests/Browser/fixtures/sentinel-theme.json`. Commit both.
- Never edit the generated stylesheet by hand.

**Icons:**
- To add one, list it in `scripts/sync-icons.mjs`, run `npm run icons`, and commit `resources/icons/`.

**Previews:**
1. Run `npm run build`.
2. Start the app (the tests start `php artisan serve` if nothing is listening on port 8000).
3. Run `PREVIEWS=1 npx playwright test previews`.

This overwrites `docs/design/previews/`. Commit the files that changed.
