# Local setup and tests

- **Status:** M1. Front-end tooling added with WP6's visual foundation.
- **Owner:** the architect.

## Requirements

| Tool | Version |
|---|---|
| PHP | 8.4, with `pdo_mysql`, `mbstring`, `intl` and `sodium` |
| Composer | 2.x |
| MySQL | 8.4 LTS (CI uses 8.4; 8.0 works locally for now) |
| Node | 22 LTS, with npm 10 (for the front-end build and the browser tests) |
| Git | any recent version |

Front-end packages are pinned to exact versions in `package.json` and `package-lock.json` ([ADR 0003 §12](../adr/0003-web-workspaces-and-study-content.md#12-dependencies)): Vite 8.3.1, laravel-vite-plugin 3.2.0, Tailwind CSS 4.3.3, @fontsource-variable/inter 5.3.0, lucide-static 1.48.0, @playwright/test 1.63.0 and @axe-core/playwright 4.13.0.

## First-time setup

```bash
git clone <repository> vistud && cd vistud

# 1. Databases and users (once per machine, as a MySQL administrator)
sudo mysql < database/scripts/local-mysql-users.sql
#    or: mysql -uroot -p < database/scripts/local-mysql-users.sql

# 2. Dependencies, .env, app key, and migrations (as the schema owner)
composer setup

# 3. Front-end dependencies and assets
npm ci
npm run build
```

`composer setup` runs `composer install`, copies `.env.example` to `.env` if it doesn't exist, generates the application key, and runs `composer migrate`. `npm run build` compiles the stylesheet and scripts into `public/build/`; the server needs no `node_modules` at run time.

To run the app locally:

```bash
php artisan serve        # http://localhost:8000; /login is the login screen
npm run dev              # optional, in a second terminal: rebuilds and reloads as you edit
```

The login screen is the first WP6 screen; the others follow after the PM's visual review. The rest of M1 is exercised through tests, the console and the JSON endpoints.

## Themes and front-end assets

- Themes are data in `resources/themes/*.json` ([DESIGN.md §3](../../DESIGN.md#3-colour-themes-and-gradients)). After changing one, run `php artisan vistud:themes:build`: it checks every theme's contrast and regenerates `resources/css/themes/themes.css` and the sentinel fixture used by the browser tests. CI fails if they are out of date (`vistud:themes:build --check`).
- Icons come from `lucide-static`. To add one, list it in `scripts/sync-icons.mjs` and run `npm run icons`.
- The logo assets in `public/brand/` are generated from the preserved original by `php resources/brand/clean-logo.php` (DESIGN.md §2.1).

## Local accounts

Either seed the synthetic accounts (local only; the seeder refuses to run in production):

```bash
php artisan migrate:fresh --database=mysql_owner --seed
#   owner@vistud.test      student and admin
#   student.a@vistud.test  student
#   student.b@vistud.test  student
#   password for all:      local-password-only
```

or set up an account the way a real installation does (ADR 0003 §10.3):

```bash
php artisan vistud:account:create you@example.com --name="Your Name" --timezone=Europe/London
php artisan vistud:admin:grant you@example.com
# If an admin loses their 2FA device and codes, and no other admin can help:
php artisan vistud:admin:reset-2fa you@example.com
```

Admins must set up two-factor authentication before entering the admin workspace. Every one of these commands is written to the audit log as a system action.

## Database users

There are two MySQL users, on purpose:

| User | Used for | Privileges |
|---|---|---|
| `vistud_owner` | Migrations only (`--database=mysql_owner`) | Everything on `vistud` and `vistud_test`, with the right to grant |
| `vistud_app` | The application and the tests | Per table, granted after every migration by `php artisan vistud:db:grants`: `SELECT, INSERT` on `audit_log`, `SELECT` on `migrations`, and `SELECT, INSERT, UPDATE, DELETE` on the rest. No schema changes |

This is how the audit log is append-only at the database-permission level (ADR 0003 §14, M1). `tests/Feature/Platform/AuditLogPermissionsTest.php` proves it, and fails if the tests run as the owner.

**Always migrate as the owner:**

```bash
composer migrate                                      # = php artisan migrate --database=mysql_owner --force
php artisan migrate:fresh --database=mysql_owner      # rebuild the local database
php artisan vistud:db:grants                          # re-apply grants by hand (normally automatic)
```

Running `php artisan migrate` without `--database=mysql_owner` fails with "command denied", because the runtime user can't create tables. That is intended.

**The local passwords** (`owner-local-only`, `app-local-only`) are for local machines and CI only. Deployments set their own users and passwords in `.env`: `DB_USERNAME`/`DB_PASSWORD` for the runtime user, `DB_OWNER_USERNAME`/`DB_OWNER_PASSWORD` for the owner, and `DB_RUNTIME_HOSTS` for the host part of the runtime user (`%` by default).

## Running the tests

```bash
composer test                               # every suite, including Acceptance
php artisan test --testsuite=Unit           # pure tests, no database
php artisan test --testsuite=Feature
php artisan test --testsuite=Architecture
php artisan test --filter=ErrorEnvelope     # one test class or method
composer lint                               # code style (Pint); vendor/bin/pint fixes it
```

### Browser tests

Playwright runs the sentinel-theme check, the live theme-switching test and the accessibility checks against `php artisan serve` (started for you if it isn't already running on port 8000). They need the built assets and a migrated local database.

```bash
npm run build
npx playwright install chromium             # once, downloads Playwright's browser
npm run test:browser                        # or: npx playwright test theme-sentinel
PREVIEWS=1 npx playwright test previews     # regenerate docs/design/previews/ for UI handoff
```

To use a Chromium that is already installed instead of downloading one, set `PLAYWRIGHT_CHROMIUM_PATH` to its executable.

### Notes

- Tests use the `vistud_test` database as `vistud_app`. The settings are in `phpunit.xml`; real environment variables (as in CI) take precedence.
- Tests that touch the database use `Tests\Concerns\RefreshesDatabase`. The schema is built once per run by the owner, and each test runs inside a transaction that is rolled back.
- Nothing needs a network connection, Qdrant or an LLM.
- The Architecture suite's compiled-CSS scan reads `public/build/`. Without a build it is skipped locally; CI always builds first.

## Continuous integration

`.github/workflows/ci.yml` runs on every push and pull request. The **test** job uses MySQL 8.4 as a service and the same database script as above, then runs `composer lint`, builds the front-end assets, and runs the Unit, Feature and Architecture suites (including both theme scans). The **browser** job builds the assets, migrates a database, and runs the Playwright tests. The Acceptance suite runs as a separate step that reports without failing the build, until the PM declares the M1 gate ([m1-work-packages.md](../handoff/m1-work-packages.md#rules-for-everyone)).

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| `Access denied for user 'vistud_app' to database 'vistud_test'` | A test uses Laravel's `RefreshDatabase` directly, so it migrates as the runtime user. Use `Tests\Concerns\RefreshesDatabase` |
| `CREATE command denied to user 'vistud_app'` | You ran a migration as the runtime user. Add `--database=mysql_owner` |
| `AuditLogPermissionsTest` says tests must run as the runtime user | `DB_USERNAME` is the owner (or root). Use `vistud_app` |
| `REVOKE IF EXISTS` syntax error | MySQL is older than 8.0.30. Upgrade to 8.4 |
