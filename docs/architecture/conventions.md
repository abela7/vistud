# Conventions: authentication, authorization, validation and errors

- **Status:** M1, increment 1. Stable unless marked otherwise.
- **Owner:** the architect.
- **Related:** [contracts.md](contracts.md), [ADR 0003 §10](../adr/0003-web-workspaces-and-study-content.md#10-student-and-admin-workspaces).

These rules apply to every work package. Where code already enforces a rule, the enforcing class or test is named.

## Authentication

- **Web sessions through Fortify:** login, logout, two-factor challenge, recovery codes, password confirmation and password reset. **Registration is off**; accounts come from invitations or the console (ADR 0003 D4). Only active accounts can log in, and a refused login looks the same whatever the reason.
- **Fortify's own endpoints** (`/login`, `/user/...`) keep Fortify's success formats. Their errors use the envelope, like everything else.
- **The JSON API uses the same session.** `/api/v1` runs on the `web` middleware group, so browser calls send the session cookie and the `X-XSRF-TOKEN` header. There are no API tokens until Passport arrives in M6 for external clients.
- **Account status is checked on every authenticated request,** from the database. A suspended account is logged out and gets `403 access_revoked`. A deleted account gets `401 account_deleted`.
- **Two-factor authentication** counts only once confirmed (`users.two_factor_confirmed_at`). It is mandatory for everything admin (D5): every `/admin` route and every admin service. An admin who hasn't enrolled can still use their own student workspace. *(PM decision Q1: [STATUS.md](../coordination/STATUS.md#decisions).)*
- **Recovery codes are shown once:** one read after they are generated in a session, then `403 recovery_codes_already_shown`.
- **Password confirmation** is Laravel's session timestamp (`auth.password_confirmed_at`). "Recent" means within the last 600 seconds, for the services (`config('vistud.access.password_confirmation_seconds')`) and for Fortify's two-factor endpoints (`config('auth.password_timeout')`) alike.
- **The `Principal`** is built once per request by `Identity\PrincipalFactory` and passed to services. The console builds `Principal::system()` only for the bootstrap commands ADR 0003 §10.3 names.

## Authorization

Three layers, and only the second one is the real control:

1. **Routes and screens** fail fast and give a good experience: the `/admin` middleware stack, hidden buttons, redirects to 2FA setup. **Hiding something is never a control.**
2. **Services** check every call through `App\Platform\Access\Guard`, whatever the adapter. A Livewire action, an API call and a console command reach the same check.
3. **The data layer** (`App\Platform\Database\LearnerTables`) adds the learner filter to every query on learner data, so a missing check returns fewer rows, never another learner's.

**Rules:**

| Rule | How |
|---|---|
| A student reaches only their own learner stream | `LearnerScope::of($principal)`. Other learners' records raise `NotFound` (404), exactly like missing records (D6) |
| Admin status grants nothing over learning content | `LearnerScope::of()` looks only at the student role. Admin services have no methods that return content |
| Admin actions need the admin role and confirmed 2FA | `Guard::admin()` |
| Protected admin actions also need a recent password confirmation | `Guard::protectedAdmin()` |
| The console's system principal is refused by default | Services opt in per operation with `allowSystem: true`. Only account creation, granting admin and resetting 2FA do |
| A student on an admin route gets 403 | `App\Http\AdminRoutes`, including a fallback for unknown `/admin` URLs |
| No operation may leave zero active admins | `App\Identity\AdminSafeguard`, with the active admins' rows locked, so concurrent requests run one after the other |
| Roles are never set by mass assignment | `role` and `status` aren't fillable. Invitation acceptance takes no role |
| Livewire components can't be trusted with IDs | IDs are `#[Locked]`, and every action calls a service that authorises again |

## Validation

- **Adapters validate shape:** types, lengths, required fields. They use form requests or Livewire rules and pass only `$request->validated()` to services. Failures are `422 validation_failed` with `details.fields`.
- **Services validate meaning:** vocabularies, references, permissions and ADR rules. They never assume the adapter did its job. Failures are `Unprocessable` with a specific code, such as `invalid_entry`, `unknown_reference` or `review_not_allowed`.
- **Journal entries** are checked against ADR 0002 by `Brain\Journal\EntryValidator`. References must resolve inside the learner's own stream.
- **Messages never echo submitted values or stored content.** They name fields and rules only.

## Errors

**Services throw `App\Platform\Errors\AppError` subclasses** for expected failures. They are not logged as application errors. Anything else is an unexpected error: it is logged with the request ID and returned as `500 server_error`, without its message.

**The JSON error envelope** (`App\Platform\Http\ErrorEnvelope`) is used for every `/api/*` response and every request that expects JSON:

```json
{
  "error": {
    "code": "version_conflict",
    "message": "The note has changed since you opened it.",
    "request_id": "0199a7c2-…",
    "details": { "current_version": 7 }
  }
}
```

- `code` is stable and machine-readable ([the list](contracts.md#error-codes)). Clients branch on `code`, never on `message`.
- `message` is short, human-readable and free of personal content.
- `request_id` matches the `X-Request-Id` header and the logs.
- `details` appears only when there is something to add.

**In browser pages,** `AppError`s become the standard error pages (404, 403 and so on). `PasswordConfirmationRequired` redirects to the confirm-password page, and `TwoFactorRequired` to 2FA setup, once those routes exist (`App\Platform\Http\WebErrors`).

## IDs

- **New IDs are UUIDv7** (`App\Platform\Ids::new()`), whether the server or a client creates them. Client-created IDs make creation instant and retries safe (ADR 0003 §3).
- **The domain accepts ID tokens:** 1–64 characters of letters, digits, `.`, `_` and `-`, starting with a letter or digit (`Ids::TOKEN_PATTERN`). That keeps golden-replay fixtures readable. API endpoints where clients create IDs require UUIDs.
- **Nothing relies on IDs being hard to guess** (ADR 0003 §10.4).

## Time

- **Store UTC.** Convert to the learner's time zone (`learners.timezone`) only for day and week precision, and for display.
- **The server's clock is the only trusted one.** `received_at` is always set by the server. Device times are recorded but not trusted (ADR 0002 §3).
- **Use `now()`** (Carbon) in services, so tests can use `travelTo()`. Pure code (`Brain\Journal`, `Brain\Projection`) takes time as an argument.
- **The API speaks ISO 8601 with an offset.**

## Idempotency

- **Journal entries:** the same ID with the same content is a duplicate (`200`, status `duplicate`). The same ID with different content is `409 id_conflict`. `capture_key` works the same way (`409 capture_key_conflict`).
- **Other writes** will carry an `Idempotency-Key` header (ADR 0003 §3). *Draft: defined with the first endpoint that needs it, in M2.*

## JSON API

- **Prefix** `/api/v1`, route names `api.v1.*`, routes in `routes/api/v1.php`.
- **Request and response bodies** are JSON objects. Resources are returned as the top-level object; lists as `{ "data": [...], "next_cursor": "..." }`.
- **Pagination** is cursor-based (`?cursor=`). *Provisional until the first list endpoint.*
- **Status codes:** `200` read or duplicate, `201` created, `204` no content, and the error statuses in [contracts.md](contracts.md#error-codes).
- **Headers:** every response has `X-Request-Id`; authenticated responses have `X-Account-Id`.
- **Every endpoint is listed** in [contracts.md](contracts.md#http-api) with its stability, and in the OpenAPI definition once that exists.

## Privacy in code

- **No personal text** in logs, exception messages, queue payloads, audit metadata or error responses. Use IDs (ADR 0001, day-one rule 5).
- **Learner tables only through `LearnerTables`.** `tests/Architecture/LearnerIsolationTest.php` fails the build otherwise.
- **Free text only in content fields**, never in columns that SQL filters or sorts on.

## Tests

- **PHPUnit 12.** Suites: `Unit` (pure, no database), `Feature` (services, HTTP, database), `Architecture` (structural rules), and `Acceptance` (independent acceptance tests; the suite is added with its first tests).
- **Tests that touch the database use `Tests\Concerns\RefreshesDatabase`**, never Laravel's `RefreshDatabase` directly. It builds the schema as the owner and runs the test as the restricted runtime user, like production.
- **MySQL, not SQLite.** Locking, JSON, privileges and time zones behave differently in SQLite.
- **Time** is controlled with `travelTo()`.
- **Name tests after the rule they prove,** citing the ADR section where it helps.
- **Acceptance tests are written from the ADRs and specs by someone other than the implementer**, and the implementer does not edit them ([m1-work-packages.md](../handoff/m1-work-packages.md)).

## Code style and commits

- **Laravel Pint** with its default preset: `composer lint` checks, `vendor/bin/pint` fixes. CI fails on style.
- **Comments explain why,** citing the ADR section that decided it, for example `(ADR 0003 §10.2)`.
- **One branch per work package,** small pull requests, and green CI before review. The pull request description lists every contract change it makes.
