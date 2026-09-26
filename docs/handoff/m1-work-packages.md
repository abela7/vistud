# M1 work packages (proposal)

- **Status:** Proposal for the PM. The PM assigns owners and approves milestone completion. The live state of each package is in [STATUS.md](../coordination/STATUS.md).
- **Author:** the architect (backend lead).
- **Scope:** Milestone M1, "Minimum foundations" ([ADR 0003 §13](../adr/0003-web-workspaces-and-study-content.md#13-milestones-d8-revised)). Nothing here reaches into M2 or later.
- **Related:** [modules.md](../architecture/modules.md), [contracts.md](../architecture/contracts.md), [conventions.md](../architecture/conventions.md), [setup.md](../development/setup.md).

## The M1 gate

M1 is complete when the PM confirms all of these ([ADR 0003 §14](../adr/0003-web-workspaces-and-study-content.md#m1-foundations)):

1. The golden replay passes: all 13 checkpoints, assertions A1–A6, and variants V1–V5.
2. The edge cases pass: task identity X1–X5, disputes D1–D6, redaction and restore R1–R3.
3. Security tests T1–T10 pass.
4. The first admin can only be created from the console, admins must enrol in 2FA, and recovery codes are shown once.
5. The audit log is append-only at the database-permission level. *(Done in WP1: `AuditLogPermissionsTest`.)*

## Packages at a glance

| ID | Package | Proposed owner | Needs first | Size |
|---|---|---|---|---|
| **WP1** | Foundations and contracts | Architect | — | Delivered in increment 1, awaiting review |
| **WP2** | Identity and access services | Architect | WP1 | Delivered in increment 2, awaiting review |
| **WP3** | Journal writer and store | Architect | WP1 | Delivered in increment 3, awaiting review |
| **WP4** | Projection engine to `rules@1` | Architect | WP1 (entry format) | Medium |
| **WP5** | Redaction, clean-up and canonical files | A second backend developer | WP1, WP3's writer | Medium |
| **WP6** | M1 web adapters: auth screens and security test surfaces, on the shared visual foundation | The architect (assigned by the PM) | WP2's service contracts | Medium |
| **V1** | Golden replay and edge-case acceptance tests | An independent validator | WP1 (entry format, projection output) | Medium |
| **V2** | Security acceptance tests T1–T10 | An independent validator (ideally not V1's) | WP2, WP3, WP6 | Medium |

```
WP1 ──┬── WP2 ──┬── WP6 ──┐
      │         └─────────┴── V2
      ├── WP3 ──┬── WP5 ──┐
      │         └─────────┼── V2 (T3 reads through WP3)
      ├── WP4 ────────────┤
      └── V1 (starts now) ┴── the M1 gate
```

**Order of the architect's own work:** WP2 first (WP6 and V2 wait on it; delivered in increment 2), then WP3 (WP5 waits on the writer), then WP4 against V1's tests as they land.

**Parallel from today:** V1 can start immediately, because the entry format and projection output are Stable. WP5 can start on the file service and outbox straight away, and pick up the writer when WP3 lands. WP6 can start once WP2's service signatures are merged.

## Rules for everyone

- **One branch per package,** named `m1/<id>-<slug>` (for example `m1/wp5-redaction`), with small pull requests. CI must be green before review. The integration branch is `claude/persistent-study-context-zsilo6` (PM decision).
- **Files have one owner.** Each package lists its files below. Shared files have the owners listed in [modules.md](../architecture/modules.md#shared-files). If you need a change in a file you don't own, ask in your pull request or open a `contract-change` issue.
- **Contracts change only through [the contract-change process](../architecture/contracts.md#changing-a-contract).**
- **Acceptance tests are independent.** Validators write them from the ADRs and specs only. Implementers never edit them. If a test and the ADR disagree, or the spec is ambiguous, the validator raises it with the PM; nobody resolves it by editing the other side's files.
- **Acceptance tests may land before the code passes them.** They run in their own CI job, which reports but does not block, until the PM declares the M1 gate. After that it blocks like any other test.
- **No deferred features.** Anything ADR 0003 places in M2 or later stays out, including styling and themes.

---

## WP1 · Foundations and contracts

**Owner:** architect. **Status:** delivered in increment 1, awaiting PM review.

**Delivered:**
- Project structure and module boundaries ([modules.md](../architecture/modules.md)).
- The initial schema for identity, audit, journal, redaction and projections, with stability labels ([schema.md](../architecture/schema.md)).
- Contracts and stability levels, error codes and audit action names ([contracts.md](../architecture/contracts.md)).
- Conventions for authentication, authorization, validation and errors ([conventions.md](../architecture/conventions.md)).
- `App\Platform`: `Principal`, `Role`, `Guard`, `LearnerScope`, `LearnerTables`, `RuntimeGrants`, the error classes, the JSON error envelope, request IDs and the account header.
- Two database users, with per-table grants applied after every migration. The audit log is append-only at the permission level.
- CI (lint and tests on MySQL 8.4), setup instructions ([setup.md](../development/setup.md)).

**Tests:** `ErrorEnvelopeTest`, `AuditLogPermissionsTest`, `LearnerIsolationTest` (architecture), `GuardTest`.

**Owns:** `app/Platform/**`, `app/Console/Commands/GrantRuntimePrivileges.php`, `database/migrations/**`, `database/scripts/**`, `docs/architecture/**`, `docs/development/**`, `docs/handoff/**`, `tests/Concerns/**`, `tests/Architecture/**`, `tests/Feature/Platform/**`, `tests/Unit/Platform/**`, and the shared files.

---

## WP2 · Identity and access services

**Owner:** architect (proposed). **Needs:** WP1. **Status:** delivered in increment 2, awaiting PM review. Developer tests: `tests/Feature/Identity/**`, `tests/Feature/Audit/**`, `tests/Feature/Api/OpenApiContractTest.php`.

**Scope** (ADR 0003 §10.2–10.3):
- Install and configure **Fortify 1.40**: login, logout, two-factor with confirmation, recovery codes, password confirmation, password reset. Registration off. Views are left to WP6.
- **Services:** `PrincipalFactory`, `Accounts`, `Roles`, `Invitations`, `TwoFactorReset`, `Workspaces`, `AuditLog` ([contracts.md](../architecture/contracts.md#identity-and-audit-increment-2-implemented)).
- **The last-admin safeguard** across revoking, suspending, deleting and self-deletion, with the admin rows locked so two concurrent requests can't both succeed.
- **Middleware:** `account.active`, `role`, `two_factor`, `admin.workspace`, and the `/admin` route group that combines them.
- **Console commands:** `vistud:account:create {email}`, `vistud:admin:grant {email}`, `vistud:admin:reset-2fa {email}`, all audited as system actions.
- **API:** `GET /api/v1/me`.
- Seeders for the owner account and synthetic student accounts (local only).

**Acceptance criteria:**
- Every rule in ADR 0003 §10.2–10.3 has a developer test that names it.
- A protected action without a password confirmation from the last 10 minutes is refused by the **service**, whichever adapter calls it.
- The system principal is refused by every service except account creation, granting admin and resetting 2FA.
- Refusing to leave zero active admins holds under two concurrent requests (tested with two connections).
- Audit records are written for every action in the [audit list](../architecture/contracts.md#audit-actions), with no email addresses or content in them.
- `GET /api/v1/me` matches its OpenAPI definition.
- The service signatures in contracts.md become Stable when this merges.

**Owns:** `app/Identity/**`, `app/Audit/**`, `app/Http/AdminRoutes.php`, `app/Http/Middleware/**`, `app/Http/Controllers/{WorkspaceController,InvitationAcceptanceController}.php`, `app/Http/Controllers/Api/V1/MeController.php`, `app/Console/Commands/{CreateAccount,GrantAdmin,ResetTwoFactor}.php`, `app/Providers/FortifyServiceProvider.php`, `config/fortify.php`, `app/Models/User.php`, `database/factories/**`, `database/seeders/**`, `routes/web/admin.php` (the protected group), `routes/web/identity.php`, `docs/api/openapi.json` (WP3 adds its endpoint), `tests/Support/OpenApi.php`, `tests/Concerns/CreatesAccounts.php`, `tests/Feature/Identity/**`, `tests/Feature/Audit/**`, `tests/Feature/Api/**`.

---

## WP3 · Journal writer and store

**Owner:** architect (proposed). **Needs:** WP1. **Status:** delivered in increment 3, awaiting PM review. Developer tests: `tests/Feature/Brain/**`, `tests/Unit/Brain/Journal/**`, `tests/Feature/Api/JournalEntryApiTest.php`.

**Scope** (ADR 0002 §3–6, §9):
- Bring `Vocabulary` and `EntryValidator` up to the latest ADR 0002 revision: `course` and `module` records; `task_revision` on attempts; `checker {id, version, key_source}` required with `judged_by: auto`, and a learner-written key refused as `auto`.
- **`JournalWriter`:** validation; positions assigned under a lock on the learner row; duplicate and conflict handling for IDs and capture keys; references resolved only inside the learner's stream; review refusals under ADR 0002 §6; content and mentions stored separately; the reference index written in the same transaction; `received_at` always set by the server.
- **`JournalReader`** and **`ProjectionRunner`**, with the snapshot cache.
- **API:** `GET /api/v1/journal/entries/{id}`.

**Acceptance criteria:**
- Every writer rule has a developer test, including: a reference to another learner's entry gets exactly the same `unknown_reference` error as a missing one; a learner's attempt to reject a verdict on their own work is refused with a pointer to `dispute` (ADR 0002 §6, case D1).
- Appending 1,000 entries for one learner from two concurrent connections gives 1,000 distinct, gap-free positions.
- Replaying stored entries through `ProjectionRunner` gives the same output as projecting the same specifications in memory.
- No code outside `Brain/Store` and `Platform/Database` touches journal tables (the architecture test).

**Owns:** `app/Brain/Journal/**`, `app/Brain/Store/**`, `app/Brain/Writer/**`, `app/Brain/Projection/ProjectionRunner.php`, `app/Http/Controllers/Api/V1/JournalEntryController.php`, `tests/Concerns/BuildsJournalEntries.php`, `tests/Feature/Brain/Writer/**`, `tests/Feature/Brain/Store/**`, `tests/Unit/Brain/Journal/**`, `tests/Feature/Api/JournalEntryApiTest.php`.

---

## WP4 · Projection engine to `rules@1`

**Owner:** architect (proposed). **Needs:** WP1. Works against V1's tests.

**Scope:** make `app/Brain/Projection` (the draft `Replay`, `Derivation` and `Projector`, currently untested) implement ADR 0002 §6–7 exactly, until V1's acceptance tests pass.

**Acceptance criteria:**
- All V1 tests pass: CP1–CP13, A1–A6, V1, V2, V4 and V5; X1–X5; D1–D6.
- Deterministic: projecting the same entries twice gives identical JSON, and the order the entries are supplied in doesn't matter.
- The projection reads no content text, touches no database and calls no LLM.
- Developer tests cover each rule in ADR 0002 §7 on its own, beyond the golden scenario.
- Where passing a test would mean departing from the ADR, the architect raises it with the PM instead of changing the rule.

**Owns:** `app/Brain/Projection/**`, `tests/Unit/Brain/Projection/**` (developer tests, not the acceptance tests).

---

## WP5 · Redaction, clean-up and canonical files

**Owner:** a second backend developer (proposed). **Needs:** WP1 now; WP3's writer for the redaction amendment.

**Scope** (ADR 0002 §10, ADR 0001 "Source files"):
- **`RedactionService::redact()`:** step 1 in one MySQL transaction. Append the `redact` amendment; delete the content fields and their mentions; delete derived claim content; insert block entries; increase the learner's cache version; mark summaries stale (none exist in M1); queue clean-up tasks. The redaction-ledger entry is written first.
- **The redaction ledger:** append-only, on its own storage disk, outside MySQL and outside the file backups. It never holds content.
- **The outbox worker:** idempotent tasks, verification after each deletion, retries after 1 minute, 5 minutes, 30 minutes, 2 hours, 12 hours and then every 24 hours; "clean-up overdue" after 5 failures. In M1 the retrieval-index task is a no-op that succeeds, because Qdrant arrives in M3.
- **`CanonicalFileService`:** per-learner storage, a content hash, refusing to open blocked sources, and a sweeper for files with no record after 24 hours.
- **`brain:reconcile-redactions`** (the name the spec uses) and a health check that fails while the ledger is ahead of the database.

**Acceptance criteria:**
- V1's redaction tests pass: V3, R1, R2 and R3.
- File deletions never share the MySQL transaction.
- No content appears in outbox payloads, the ledger, `last_error` or logs (tested with marker strings).
- Every table change goes through the architect as a migration review.

**Owns:** `app/Brain/Redaction/**`, `app/Platform/Outbox/**`, `app/Platform/Files/**`, `app/Console/Commands/{ReconcileRedactions,RunOutbox,SweepOrphanFiles}.php`, the storage-disk entries in `config/filesystems.php` (reviewed by the architect), `tests/Feature/Brain/Redaction/**`.

---

## WP6 · M1 web adapters

**Owner:** the architect (assigned by the PM). **Needs:** WP2's service signatures.

**Scope:** only the screens M1 needs for its gate and for the owner to use it, **styled with the shared visual foundation in [DESIGN.md](../../DESIGN.md)**: the brand, theme tokens and gradients, and the shared components. This replaces the earlier "plain, unstyled markup" direction (PM decision, recorded in [ADR 0003 §16](../adr/0003-web-workspaces-and-study-content.md#16-decisions)). The workspace shell, the theme editor, the calendar, the note editor and every other M2 screen stay out of scope.
- **First:** DESIGN.md, the theme system and the login screen, with desktop and mobile previews in light and dark and an alternate gradient palette, for the PM's visual review. The other screens are styled after that review.
- The front-end tooling pinned in ADR 0003 §12 arrives with it: Vite, Tailwind, the Inter font, Lucide icons, Playwright and axe.
- Install **Livewire 4.4.6**.
- **Fortify views:** login, two-factor challenge, two-factor setup (QR code, confirmation, recovery codes shown once), confirm password, forgot and reset password.
- **Invitation acceptance** page.
- **The workspace switch**, shown only to admins, and an admin landing page with the admin marker.
- **The Livewire surfaces the security tests need,** as thin adapters over services:
  - `Admin\Accounts\Index`: list accounts; suspend, reactivate, grant and revoke admin (T2, T6, T9, T10);
  - `Admin\AuditLog\Index`: read-only list (T8);
  - `Journal\EntryShow`: shows one of the student's own entries by a `#[Locked]` ID (T3).
- Plain error pages for 403 and 404.

**Acceptance criteria:**
- The owner can do the whole flow in a browser: create the first admin from the console, log in, enrol in 2FA, save the recovery codes, enter the admin workspace, invite a synthetic student; the student accepts, logs in and opens one of their own entries.
- Components contain no business rules and no queries on learner tables. Every action calls one service.
- Every ID a component receives from the browser is `#[Locked]`.
- Screens use only the shared foundations. The theme checks (source scan, compiled-CSS scan and sentinel test, gradients included) pass on every WP6 screen and state, and each screen passes the handoff checks in DESIGN.md §10.

**Owns:** `app/Livewire/**`, `resources/views/**`, `routes/web/auth.php`, `routes/web/student.php`, `routes/web/admin-screens.php` (loaded inside the protected admin group), `app/Providers/FortifyViewsServiceProvider.php`, `tests/Feature/Web/**`, and its screens' browser tests and previews in `tests/Browser/`. The shared visual foundation (DESIGN.md, themes, styles, scripts and the theme enforcement tests) is listed under the architect's shared files in [modules.md](../architecture/modules.md#shared-files). Turning on Fortify's views (`'views' => true` in `config/fortify.php`) is a one-line change the architect makes when WP6 asks.

---

## V1 · Golden replay and edge-case acceptance tests (independent)

**Owner:** an independent validator (proposed). **Needs:** WP1 only, to start.

**Scope:** turn [docs/specs/golden-replay-sql-joins.md](../specs/golden-replay-sql-joins.md) into executable tests, **from the spec and ADR 0002 alone**:
- `GoldenReplayTest`: the fixture (positions 1–86), checkpoints CP1–CP13, assertions A1–A6, variants V1, V2, V4 and V5;
- `TaskIdentityTest`: X1–X5;
- `DisputeTest`: D1–D6;
- `RedactionTest`: V3, R1–R3, once WP5's service exists. These run against the database.

**How:**
- Fixture entries use the [entry specification format](../architecture/contracts.md#journal-entry-specification-stable). Pure tests call `Projector::project()` directly; the redaction tests go through the writer and the redaction service.
- The spec's fixture settings now say how "auto" outcomes record their checker, and that times carry offsets with `tz: Europe/London`, because the entry format requires both.
- Assertions use the [projection output keys](../architecture/contracts.md#projection-output-stable-keys-provisional-facts), and check only what the spec states.
- Ambiguities and apparent contradictions go to the PM as issues. The validator doesn't guess, and doesn't change the spec.

**Acceptance criteria:**
- Every checkpoint, assertion, variant and edge case in the spec maps to at least one named test. A table in the test file's docblock shows the mapping.
- The PM reviews the tests against the spec before they count towards the gate.
- The tests never read or depend on the internals of `app/Brain/Projection` (only `Projector`, `ProjectionOptions` and the entry format).

**Owns:** `tests/Acceptance/Brain/**`, `tests/Acceptance/Fixtures/**`.

---

## V2 · Security acceptance tests T1–T10 (independent)

**Owner:** an independent validator, ideally not V1's. **Needs:** WP2, WP3 (the entry endpoint) and WP6.

**Scope:** ADR 0003 §10.5, T1–T10, through **every adapter that exists in M1**: HTTP routes, Livewire components (including forged and tampered requests), the JSON API and the console. MCP tools don't exist until M6; T3's MCP case is added then.

**Specifically:**
- **T1** walks the route list: every route under `/admin` answers 403 to a student. A new admin route is covered automatically.
- **T3 and T4** plant unique canary strings in learner B's content and assert they never appear in any response to learner A or to an admin.
- **T9** covers the service path from Livewire, from the API, and from a console path acting as a user rather than the system.
- **T10** includes two concurrent requests that would each remove one of the last two admins.

**Acceptance criteria:**
- Each of T1–T10 is at least one named test, with a docblock mapping it to the ADR text.
- **Testing the tests:** at least three deliberate breaks, each on a throwaway branch, make the suite fail: removing the learner filter in `LearnerTables`, removing `Guard::protectedAdmin()` from one service, and removing `#[Locked]` from one component. The results are recorded in the pull request.

**Owns:** `tests/Acceptance/Security/**`.

---

## Questions for the PM (decided)

Q1–Q3 were decided by the PM; the decisions are recorded in [STATUS.md](../coordination/STATUS.md#decisions).

| # | Question | Decision |
|---|---|---|
| Q1 | Does an admin without 2FA lose access to everything, or only to the admin workspace? | Only admin access and operations; the account's own student workspace stays available |
| Q2 | Fortify 1.40 requires `laravel/passkeys` and WebAuthn libraries. Acceptable? | Yes, with passkeys disabled |
| Q3 | Is a link shown once to the admin enough, without email, for the pilot? | Yes: manually shared, expiring, single-use links, with tokens kept out of logs |
