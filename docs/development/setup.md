# Local setup and tests

- **Status:** M1, increment 1.
- **Owner:** the architect.

On this branch M1 is backend only, so Node isn't needed. Front-end tooling (Vite, Tailwind, Playwright) arrives with WP6, on the `m1/wp6-web-adapters` branch ([PR #3](https://github.com/abela7/vistud/pull/3)); that branch's copy of this page covers Node and the asset build. The local handover is described in [LOCAL-TAKEOVER.md](../handoff/LOCAL-TAKEOVER.md).

The supported and tested environment is **PHP 8.4** and **MySQL 8.4 LTS**. CI runs those versions on Ubuntu. `composer.json` requires `"php": "^8.3"`. That constraint is the range Composer will accept when installing dependencies. It does not establish the supported or tested environment. Develop and test on PHP 8.4.

XAMPP's existing PHP 8.0 and MariaDB installation does not meet this baseline. On the workstation where these instructions were checked, that installation reported PHP 8.0.30 and MariaDB 10.4.32, and its `mysql` client is MariaDB. Point ViStud at PHP 8.4 and MySQL 8.4.

## Requirements

| Tool | Version |
|---|---|
| PHP | 8.4, with `pdo_mysql`, `mbstring`, `intl` and `sodium` |
| Composer | 2.x |
| MySQL | 8.4 LTS. This is the supported and tested database. CI runs MySQL 8.4 |
| Git | any recent version |
| Unix, for one test | `pcntl` and `posix`. Windows PHP does not provide these extensions |

## Process-forking concurrency test

`tests/Feature/Brain/Store/ConcurrentAppendTest.php` checks that two processes can append to one learner at the same time and receive distinct, gap-free positions. It calls `pcntl_fork`, `pcntl_waitpid` and `posix_kill`. Those functions exist on Unix PHP when the `pcntl` and `posix` extensions are loaded. They are absent on Windows PHP.

When `pcntl_fork` is missing, the test calls `markTestSkipped` and PHPUnit reports a skip. The other suites can still pass. A skipped concurrency test does not satisfy the M1 gate. The gate needs a Unix run in which this test executes. CI does that: `ubuntu-24.04`, PHP 8.4, with `pcntl` and `posix` installed (`.github/workflows/ci.yml`).

## First-time setup

**Unix shell only** for creating the databases and users. The commands in the next block use `&&` and shell input redirection (`<`). That is bash (and other Unix shells). It is not PowerShell. In Windows PowerShell the `<` operator is reserved and the line fails before `mysql` starts. A Windows procedure for this step has not been verified, so this page does not give one. Create the users with the Unix command below, which is the same script CI runs.

```bash
# Unix shell (bash). Not a PowerShell command.
git clone <repository> vistud && cd vistud

# 1. Databases and users (once per machine, as a MySQL administrator)
sudo mysql < database/scripts/local-mysql-users.sql
#    or, still in a Unix shell: mysql -uroot -p < database/scripts/local-mysql-users.sql
```

CI applies that script on Ubuntu with `mysql -h127.0.0.1 -uroot -proot < database/scripts/local-mysql-users.sql`.

After those users exist, install dependencies, create `.env`, generate the app key, and migrate as the schema owner:

```bash
composer setup
```

`composer setup` runs `composer install`, copies `.env.example` to `.env` if it doesn't exist, generates the application key, and runs `composer migrate`. `composer migrate` is `php artisan migrate --database=mysql_owner --force`.

To run the app locally, with PHP 8.4 available as `php`:

```bash
php artisan serve        # http://localhost:8000
```

This branch has no screens. Work package WP6 adds minimal authentication and admin screens: login, two-factor authentication, invitation acceptance, and the admin pages the security tests need. By the PM's decision they use the shared visual foundation in DESIGN.md instead of unstyled markup. The login screen and that foundation are on the `m1/wp6-web-adapters` branch; the other screens are not built yet. The persistent workspace shell (the sidebar, top bar and editor host that stay in place while the main area changes) belongs to M2. Until WP6 lands, M1 is exercised through tests, the console and the JSON endpoints.

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

- Tests use the `vistud_test` database as `vistud_app`. The settings are in `phpunit.xml`; real environment variables (as in CI) take precedence.
- Tests that touch the database use `Tests\Concerns\RefreshesDatabase`. The schema is built once per run by the owner, and each test runs inside a transaction that is rolled back.
- Nothing needs a network connection, Qdrant or an LLM.

## Continuous integration

`.github/workflows/ci.yml` runs on every push and pull request on `ubuntu-24.04`. The job uses PHP 8.4 with `pdo_mysql`, `mbstring`, `intl`, `sodium`, `pcntl` and `posix`, and MySQL 8.4 as a service. It applies `database/scripts/local-mysql-users.sql` with Unix shell redirection, then runs `composer lint` and the Unit, Feature and Architecture suites. On that runner the concurrency test executes. The Acceptance suite runs as a separate step that reports without failing the build, until the PM declares the M1 gate ([m1-work-packages.md](../handoff/m1-work-packages.md#rules-for-everyone)).

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| `Access denied for user 'vistud_app' to database 'vistud_test'` | A test uses Laravel's `RefreshDatabase` directly, so it migrates as the runtime user. Use `Tests\Concerns\RefreshesDatabase` |
| `CREATE command denied to user 'vistud_app'` | You ran a migration as the runtime user. Add `--database=mysql_owner` |
| `AuditLogPermissionsTest` says tests must run as the runtime user | `DB_USERNAME` is the owner (or root). Use `vistud_app` |
| `REVOKE IF EXISTS` syntax error | The grants statement is MySQL syntax: `REVOKE IF EXISTS ... IGNORE UNKNOWN USER` (MySQL 8.0.30 or later). MariaDB's `REVOKE` has neither clause. The supported server is MySQL 8.4. XAMPP's MariaDB installation does not meet the baseline |
| Concurrency test skipped (`Needs the pcntl extension`) | PHP has no `pcntl_fork`, which is expected on Windows. The skip does not satisfy the M1 gate. Run the suite on Unix with `pcntl` and `posix`, as CI does |
