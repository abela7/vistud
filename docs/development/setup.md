# Local setup and tests

- **Status:** M1, increment 1.
- **Owner:** the architect.

M1 is backend only, so Node isn't needed yet. Front-end tooling (Vite, Tailwind, Playwright) arrives with the M2 work packages.

## Requirements

| Tool | Version |
|---|---|
| PHP | 8.4, with `pdo_mysql`, `mbstring`, `intl` and `sodium` |
| Composer | 2.x |
| MySQL | 8.4 LTS (CI uses 8.4; 8.0 works locally for now) |
| Git | any recent version |

## First-time setup

```bash
git clone <repository> vistud && cd vistud

# 1. Databases and users (once per machine, as a MySQL administrator)
sudo mysql < database/scripts/local-mysql-users.sql
#    or: mysql -uroot -p < database/scripts/local-mysql-users.sql

# 2. Dependencies, .env, app key, and migrations (as the schema owner)
composer setup
```

`composer setup` runs `composer install`, copies `.env.example` to `.env` if it doesn't exist, generates the application key, and runs `composer migrate`.

To run the app locally:

```bash
php artisan serve        # http://localhost:8000
```

There are no screens yet: login pages and the admin shell come from work package WP6. Until then, M1 is exercised through tests and the console.

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

`.github/workflows/ci.yml` runs on every push and pull request: MySQL 8.4 as a service, the same database script as above, `composer lint`, then the Unit, Feature and Architecture suites. The Acceptance suite runs as a separate step that reports without failing the build, until the PM declares the M1 gate ([m1-work-packages.md](../handoff/m1-work-packages.md#rules-for-everyone)).

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| `Access denied for user 'vistud_app' to database 'vistud_test'` | A test uses Laravel's `RefreshDatabase` directly, so it migrates as the runtime user. Use `Tests\Concerns\RefreshesDatabase` |
| `CREATE command denied to user 'vistud_app'` | You ran a migration as the runtime user. Add `--database=mysql_owner` |
| `AuditLogPermissionsTest` says tests must run as the runtime user | `DB_USERNAME` is the owner (or root). Use `vistud_app` |
| `REVOKE IF EXISTS` syntax error | MySQL is older than 8.0.30. Upgrade to 8.4 |
