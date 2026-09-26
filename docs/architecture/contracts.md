# Contracts

- **Status:** M1, increment 1.
- **Owner:** the architect. Anyone may propose a change ([how](#changing-a-contract)).
- **Related:** [modules.md](modules.md), [schema.md](schema.md), [conventions.md](conventions.md).

A **contract** is anything another work package builds against: a PHP class or method that crosses a module boundary, a table, an API endpoint, an error code, an audit action name, or the format of journal entries and projection output. This document lists them and says how safe each one is to build on.

## Stability levels

| Level | Meaning | How it may change |
|---|---|---|
| **Stable** | Build on it now | Additively only during a milestone: new optional fields, new endpoints, new error codes. Anything that breaks callers needs PM approval, notice to every affected package, and a version bump where versions exist |
| **Provisional** | The design is settled; details may still move during M1 | The owner may change it with notice in the pull request, listing affected packages. It becomes Stable when its increment is merged, unless marked otherwise |
| **Draft** | A proposal | May change freely. Don't build on it without talking to its owner |

## Changing a contract

1. Open a pull request (or an issue first, for anything large) labelled **`contract-change`**. Say what changes, why, which packages are affected, and how callers migrate.
2. The architect reviews it against the ADRs. The PM approves it if it changes an ADR decision, a Stable contract, or another package's scope.
3. Update the affected document here, the code and the tests in the **same** pull request.
4. If an ADR is affected, update the ADR too, or open a new one.

Contracts never change silently inside an unrelated pull request.

## Platform (Stable)

Implemented in increment 1 under `app/Platform/`.

| Contract | Summary |
|---|---|
| `Access\Principal` | Who is acting: `userId`, `learnerId`, `roles` (list of `Role`), `twoFactorConfirmed`, `passwordConfirmedAt`, `channel` (`web` · `api` · `mcp` · `console` · `job`), `ip`, `userAgent`, `requestId`. `Principal::system()` is the server acting from a shell. Methods: `isSystem()`, `hasRole()`, `hasRecentPasswordConfirmation()`, `auditRole()` |
| `Access\Role` | Enum: `student`, `admin` |
| `Access\Guard` | `admin($p, allowSystem: false)`: admin role and confirmed 2FA. `protectedAdmin($p, allowSystem: false)`: also a password confirmation in the last 10 minutes. `learner($p)`: returns the principal's own `LearnerScope` |
| `Access\LearnerScope` | The one learner stream a data access may touch. `of($principal)` needs the student role; admin adds nothing. `forJob($learnerId)` is only for jobs and maintenance code handed an ID by trusted server code, and is restricted by an architecture test |
| `Database\LearnerTables` | `query($scope, $table)` and `insert($scope, $table, $rows)`: the only way to reach a learner table. `TABLES` lists them |
| `Errors\*` | `AppError` and its subclasses. Each maps to an HTTP status and an error code ([below](#error-codes)) |
| `Http\ErrorEnvelope` | The JSON error shape ([conventions.md](conventions.md#errors)) |
| `Ids` | `new()` gives a UUIDv7. `isToken()` and `isUuid()` check IDs |
| Response headers | `X-Request-Id` on every response. `X-Account-Id` on every authenticated `/api/v1` response |

## Journal entry specification (Stable)

This is the array format that both the journal writer and the golden replay fixtures use. `App\Brain\Journal\EntryFactory::make()` turns it into a `JournalEntry`, and `EntryValidator::validate()` checks it against ADR 0002 §4–5. Field meanings are in [ADR 0002 §3](../adr/0002-learning-event-schema.md#3-fields-every-event-carries-the-envelope).

```yaml
id:            ID token                  # required; UUIDv7 in production
kind:          exposure | attempt | question | self_report | claim | record | amendment
type:          core.<kind>               # default
type_version:  1                         # default
actor:         { type, id, channel }     # channel defaults to web
origin:        first_hand | reported | material      # observations only, required for them
occurred_at:   ISO 8601 with offset      # required
occurred_until: ISO 8601 with offset     # optional
precision:     exact | minute | day | week           # default exact
tz:            IANA zone                 # the writer defaults to the learner's zone
recorded_at:   ISO 8601                  # device time, untrusted
received_at:   ISO 8601                  # set by the writer; fixtures may set it
capture_key:   string, up to 128 chars   # optional
session:       ID token                  # optional
activity:      ID token                  # optional
links:         [{ rel: about | cites | responds_to | triggered_by, target: ref, role?: primary | secondary }]
mentions:      [{ n, field, start, end }]
body:          { ... }                   # per kind, ADR 0002 §4–5
content:       { <field>: text }         # free text, stored separately
```

- **Positions** are never part of the specification. The writer assigns them; pure tests assign them in fixture order.
- **Times** must carry an offset (`2026-10-13T10:00:00+01:00` or `...Z`). The writer stores them in UTC.
- **`tz`** defaults to the learner's time zone in the writer, and to `UTC` in `EntryFactory` (pure tests should set it).
- **`received_at`** is ignored by the writer, which always uses the server's time. Tests that need a particular time use `travelTo()`.
- **Unknown top-level fields are refused.** Content fields are named with lower-case words (up to 32 characters) and hold at most 1 MiB of text each.
- **Attempts** may carry `task_revision` (a positive integer). `judged_by: auto` requires `checker: {id, version, key_source}`, and `key_source` must be `course_material`, `instructor` or `derived_from_material`. A key the learner wrote (`key_source: learner`) is refused for `auto`; record the attempt as `judged_by: self` (ADR 0003 §9.4).
- **Records** of type `task` need a `key` (and may carry `revision`, `content_hash`, `status`); `activity` needs `kind`; `workspace` needs `title`; `module` needs `workspace` and `title`. (Until M2 step 0 these were `course`, and a module's `course`; renamed before any journal stored one, docs/specs/workspaces.md decision W2.)
- **Records** carry `body.record_type` and `body.record_id`. A revision appends a new record entry with the same `record_id`.
- **Claims** carry `body.type`, `targets`, `value`, `confidence`, `method {kind, id, version, prompt?}`, `derived_from`, `supersedes` and `review {state, by?}`.
- **References** are `type:id`, optionally `#locator`, for example `source:NOTE#v12/blk-7f3` ([ADR 0002 §5](../adr/0002-learning-event-schema.md#5-claim-contracts)).

Increment 3 brought `Journal\Vocabulary` and `EntryValidator` up to the latest ADR 0002 revision. The additions above (workspace and module records, `task_revision`, the checker rule, and the stricter checks on times, fields and content) are part of the Stable format.

## Projection output (Stable keys, Provisional facts)

`App\Brain\Projection\Projector::project(array $entries, ProjectionOptions $options): array` is pure: the same entries and options always give the same result. `ProjectionOptions` takes `now`, and optionally `maxPosition` (the belief view: only what had been recorded by then) or `observedUntil` (the current view at a moment: every claim, but only the observations that had occurred by then).

```yaml
rules:     rules@1
position:  <int>                          # highest position replayed
topics:
  <topic id>:
    label: not_started | introduced | developing | working | secure | durable
    flags: [sorted list]                  # regressed, claimed_only, overconfident, underconfident,
                                          # practised, needs_review, exam_relevant, weak_part,
                                          # includes_ai_judged, rests_on_dispute
    facts: { ... }                        # Provisional: supporting numbers and dates
misconceptions:
  <id>: { state, resurfaced_count, topics: [...] }
        # detected, recurring, resurfaced, addressed, apparently_resolved,
        # resolved_retained, disputed, withdrawn
questions:
  <id>: { state, flags: [...], resurfaced_count }
        # states: open, being_answered, partially_answered, answered,
        #         resolved_learner_confirmed, resolved_demonstrated
        # flags:  reopened, dormant, split, learner_thought_resolved
profile:
  <approach>: { helped, no_effect, confused, examples: [...] }
attempts:
  <event id>: { task, overall, topics: {<topic>: value}, misconceptions: {<id>: bool | disputed},
                repeat: none | immediate | delayed, checker_suspect }
rejected_pairs: [{ target, entity }]
```

The key names and the value vocabularies are Stable, because the acceptance tests assert on them. `facts` stays Provisional until M4 builds screens on it.

## Services (Provisional until their increment merges)

Every service method takes a `Principal` (or a `LearnerScope` for learner data), runs its own permission checks through `Guard`, and throws `AppError` subclasses. Signatures below are the plan; each becomes Stable when its increment is merged.

### Identity and audit (increment 2: implemented)

Provisional until the PM approves increment 2, then Stable.

| Service | Methods | Checks |
|---|---|---|
| `Identity\PrincipalFactory` | `fromRequest(Request): Principal` (cached per request) · `forUser(User, channel, ?passwordConfirmedAt, ?area, ?ip, ?userAgent, ?requestId): Principal` · `forget(Request)` | Reads status and 2FA from the database on every call. Suspended: `403 access_revoked`. Deleted or missing: `401 account_deleted` |
| `Identity\Accounts` | `create(Principal, name, email, password, student = true, ?timezone): User` · `suspend(Principal, userId)` · `reactivate(Principal, userId)` · `requestDeletion(Principal, userId)` · `details(Principal, userId): AccountDetails` · `names(Principal, userIds): array<id, name>` (for showing who did what) · `list(Principal, ?cursor, limit = 50, ?search): {data: list<AccountDetails>, next_cursor}` (`search` matches part of a name or an email address) | `create`: protected admin, or the console. `suspend`, `reactivate`: protected admin. `requestDeletion`: protected admin, or the account's own user with a recent confirmation. `details`, `names`, `list`: admin with 2FA. Last-admin safeguard on suspend and delete |
| `Identity\Roles` | `grantAdmin(Principal, userId)` · `revokeAdmin(Principal, userId)` | Protected admin. The console may grant, never revoke. Last-admin safeguard on revoke |
| `Identity\Invitations` | `invite(Principal, email): IssuedInvitation` · `revoke(Principal, invitationId)` · `pending(Principal, limit = 100): list<PendingInvitation>` · `accept(token, name, password, ?timezone): User` | Protected admin to invite and revoke; admin with 2FA for `pending` (never returns a token). `accept` takes no role, always creates a student, and answers every invalid token with the same `422 invitation_invalid` |
| `Identity\TwoFactorReset` | `reset(Principal, userId)` | Protected admin, or the console. Ends the user's sessions |
| `Identity\Areas` | `enter(Principal, Area)` | `admin`: protected admin (2FA and a recent confirmation). `student`: the student role |
| `Study\Workspaces` | `list(Principal, archived = false): list<WorkspaceDetails>` · `find(Principal, id)` · `create(Principal, input)` · `update(Principal, id, input)` · `archive(Principal, id)` · `restore(Principal, id)`; constants `ICONS`, `SECTIONS` | The principal's own learner stream only (`Guard::learner`): another student's workspace is `404 not_found`, like a missing one. Input refusals are `422 validation_failed` with `details.fields`. Every change appends a revision of the `workspace` journal record |
| `Study\Modules` | `list(Principal, workspaceId): list<ModuleDetails>` · `find(Principal, id)` · `create(Principal, workspaceId, input)` · `update(Principal, id, input)` · `move(Principal, id, position)` · `delete(Principal, id)` | As `Study\Workspaces`. `delete` answers `409 not_empty` while the module has folders. Every change appends a revision of the `module` journal record |
| `Study\Folders` | `tree(Principal, workspaceId): list<FolderDetails>` (parents before children, siblings in order, the top level last) · `find(Principal, id)` · `create(Principal, 'workspace'\|'module'\|'folder', parentId, name)` · `rename(Principal, id, name)` · `move(Principal, id, 'workspace'\|'module'\|'folder', parentId)` · `reorder(Principal, id, position)` · `delete(Principal, id)`; constants `MAX_DEPTH` (8), `MAX_NAME` | As `Study\Workspaces`, and only within one workspace. `409 too_deep` past 8 levels, `409 invalid_move` into itself, `409 not_empty` for a folder with folders inside. Not journalled |
| `Study\Notes` | `list(Principal, workspaceId): list<NoteDetails>` · `trashed(Principal, workspaceId)` · `find(Principal, id)` · `open(Principal, id)` (with its document) · `create(Principal, 'workspace'\|'module'\|'folder', placeId, title = '')` · `save(Principal, id, {base_version, save_id, client_id, title, doc, kind?}): SavedVersion` · `move(Principal, id, placeType, placeId)` · `trash` · `restore` · `destroy` (only from the trash) · `purgeTrash(LearnerScope)`; constants `MAX_TITLE`, `TRASH_DAYS` (30) | As `Study\Workspaces`. `save` answers `409 version_conflict` (with `details.current_version`) when `base_version` isn't current, `410 gone` (`reason: trashed`) for a note in the trash, and the same answer again for a retried `save_id`. Documents are cleaned by `Study\NoteDoc` (unknown nodes refused, other links than http, https and mailto dropped). Moves stay within one workspace. Modules and folders can't be deleted while notes outside the trash are in them |
| `Study\Tombstones` | `since(Principal, int since): {data, next_since, more}` | The principal's own deletion records only |
| `Audit\AuditLog` | `record(Principal, action, ?targetType, ?targetId, metadata = [], ?role)` · `list(Principal, filters = [], ?cursor, limit = 50)` | `record` refuses metadata strings that aren't ID tokens (so no email addresses or free text). `list`: admin with 2FA |

- `AccountDetails` holds id, name, email, status, roles, 2FA status, created, status changed and last active. **No admin service has a method that returns learning content** (ADR 0003 §10.4).
- `IssuedInvitation` holds id, token and expiry. The token is returned once and only its hash is stored. M1 sends no email: the admin screen (WP6) shows the link once.
- `Platform\Access\Area` (enum `student`, `admin`) and `Principal::$area` were added in increment 2 as `Workspace` and `$workspace`, and renamed in M2 step 0 so that "workspace" means only a student's subject workspace (docs/specs/workspaces.md).

**Web adapters and middleware (increment 2).** These are what WP6 builds screens on:

| Route or middleware | Behaviour |
|---|---|
| `App\Http\AdminRoutes::group()` | The `/admin` group: `auth`, `role:admin` (403 and an audit record otherwise), `two_factor` (403 `two_factor_required`, or a redirect to the `two-factor.setup` page once WP6 adds it), `admin.area` (entering needs a recent password confirmation: 423, or a redirect to the confirm-password page). WP6 adds screens in `routes/web/admin-screens.php`. Any other `/admin` URL is 403 for non-admins and 404 for admins |
| `POST /area/{student\|admin}` | Switches area. `204` for JSON, a redirect otherwise |
| `POST /invitations/accept` | `token`, `name`, `password`, `password_confirmation`, optional `timezone`. Creates the student, logs them in, `201 {id}` for JSON. A browser is sent to `/` instead; an invalid token sends it back to `/invitation` with an `invitation` error and no kept input |
| `GET /workspaces/{workspace}/{section?}` | A workspace's pages (`section`: `modules`, `notes`, `calendar`, `progress`; none for Overview). Another student's workspace answers 404 like a missing one. `/` is My workspaces for a student. Modules and Notes & files are `App\Livewire\Workspaces\Contents` (locked workspace, section and target IDs) |
| `GET /workspaces/{workspace}/notes/{note}` | A note and its editor (`resources/js/note/`), which saves through `PUT /api/v1/notes/{id}`. The note must belong to that workspace; anything else answers 404. Trash and restore: `App\Livewire\Workspaces\NoteActions`. For a student, every page carries `<meta name="vistud-account">`; the browser keeps drafts per account and asks before logging out with unsaved ones (`resources/js/note/logout.js`) |
| `GET /journal`, `GET /journal/{entry}` | The student's own journal, and one entry (`App\Livewire\Journal\EntryShow`). Another learner's entry answers 404 exactly like a missing one; an account without a journal gets 403. A tampered Livewire request (a locked property changed, or an edited snapshot) also answers 404 |
| `GET /invitation` | The acceptance page. Links are `/invitation#<token>`: the token after `#` never reaches the server, so it stays out of every log (Q3), and the page's script moves it into the form |
| Fortify endpoints | `POST /login`, `POST /logout`, `POST /two-factor-challenge`, `POST /forgot-password`, `POST /reset-password`, `PUT /user/password`, `POST /user/confirm-password`, `GET /user/confirmed-password-status`, and the `/user/two-factor-*` endpoints. Success formats are Fortify's own; errors use the envelope. `GET /user/two-factor-recovery-codes` answers once after codes are generated in the session, then `403 recovery_codes_already_shown` |
| `EnsureAccountActive` (every web and API request) | Logs out a suspended or deleted account on its next request |

### Journal (increment 3: implemented)

Provisional until the PM approves increment 3, then Stable.

| Service | Methods |
|---|---|
| `Brain\Writer\JournalWriter` | `append(LearnerScope, array $spec): AppendResult` · `appendBatch(LearnerScope, list $specs): list<AppendResult>` (all or nothing). `AppendResult` has `status` (`recorded` · `duplicate`), the stored `entry` and `position()` |
| `Brain\Store\JournalReader` | `find(LearnerScope, id): ?StoredEntry` (the entry, its content, and whether a redaction blocks it) · `entries(LearnerScope, ?upToPosition): list<JournalEntry>` · `content(LearnerScope, entryId): array` (empty when blocked) |
| `Brain\Projection\ProjectionRunner` | `project(LearnerScope, ProjectionOptions): array` · `refresh(LearnerScope, ProjectionOptions): array` (also stores the snapshot) · `latest(LearnerScope): ?array` |

**What the writer guarantees:**
- Positions per learner are strictly increasing and gap-free, even with concurrent writers (it locks the learner row; tested with two processes).
- The same ID with the same content is a `duplicate`; with different content, `409 id_conflict`. The same `capture_key` with the same content (whatever the ID) is a `duplicate`; with different content, `409 capture_key_conflict`.
- Every reference resolves inside the learner's own journal: entries through `event:` and `claim:` (each with the right prefix), entities through a `defines` claim or a record earlier in the journal or the same batch. Otherwise `422 unknown_reference`, which is the same answer for a missing ID and another learner's ID.
- An entry with a `learner` actor must be in that learner's own journal.
- Reviews ADR 0002 §6 forbids are refused with `422 review_not_allowed`. When the learner tries to accept or reject a verdict on their own work, `details.use` is `dispute`.
- Contract violations are `422 invalid_entry` with `details.field`. Messages never repeat submitted values.

**Not in M1:** the writer does not yet check that `review.state: accepted` matches `policy@1` (ADR 0002 §8). The capture services that create claims (M6) will apply the policy.

## Appearance (Provisional)

The visual foundation for every screen (WP6), under [ADR 0003 §6](../adr/0003-web-workspaces-and-study-content.md#6-themes-and-colour) and [DESIGN.md](../../DESIGN.md). Provisional until the PM's visual review of WP6.

| Contract | Summary |
|---|---|
| Theme data (`resources/themes/*.json`) | `id`, `name`, `scheme` (`light` · `dark`), `colors` (every token in `Appearance\Theme::COLORS`, `#rrggbb` or `#rrggbbaa`) and `gradients` (every token in `Theme::GRADIENTS`: `{angle, stops}` with 2–6 opaque stops at ascending positions 0–100, or `{solid}`). Unknown or missing tokens are rejected |
| CSS variables | `--{token}` for colours, `--grad-{token}` for gradients, under `[data-theme="{id}"]`; the default light theme also on `:root`. Components use only these, through the Tailwind token utilities and the `surface-*` gradient utilities |
| `Appearance\ThemeValidator` | `failures(Theme): list<{pair, minimum, actual, at, suggestion}>`. Empty means the theme passes. M2's custom themes and admin presets use the same check |
| `php artisan vistud:themes:build [--check]` | Validates the built-in themes and writes `resources/css/themes/themes.css` and the sentinel fixture. `--check` fails if either is out of date |
| `<html>` attributes | `data-theme` (the active theme), `data-theme-light`, `data-theme-dark` (the pair) and `data-appearance` (`system` · `light` · `dark`) |
| `vistud:theme-changed` | A window event, `detail: {mode, theme}`, fired when the active theme changes. Script-drawn components (charts) rebuild on it |
| Shared Blade components | `x-layouts.auth`, `x-logo`, `x-icon`, `x-button`, `x-field`, `x-checkbox`, `x-alert`, `x-appearance-switcher` ([DESIGN.md §5](../../DESIGN.md#5-components-and-their-states)) |

## HTTP API

Conventions are in [conventions.md](conventions.md#json-api). The OpenAPI definition is [`docs/api/openapi.json`](../api/openapi.json). `tests/Feature/Api/OpenApiContractTest.php` fails if a `/api/v1` route is missing from it, or a response doesn't match its schema (ADR 0003 §8).

| Endpoint | Status | Arrives | Purpose |
|---|---|---|---|
| `GET /api/v1/me` | Provisional | Increment 2 (implemented) | The account, its roles, its learner ID and the active area (`area`; called `workspace` before M2 step 0) |
| `GET /api/v1/journal/entries/{id}` | Provisional | Increment 3 (implemented) | One of the learner's own entries, with content unless blocked. Another learner's ID gets the same 404 as a missing one; an account without a learner stream gets `403 student_role_required` |
| `GET /api/v1/notes/{id}`, `PUT /api/v1/notes/{id}` | Provisional | M2 step 3a (implemented) | The editor's read and autosave ([ADR 0003 §5.3](../adr/0003-web-workspaces-and-study-content.md#53-autosave-drafts-ordering-and-conflicts)). `PUT` only updates; its body is read raw, so no request middleware changes the note's text. `409 version_conflict`, `410 gone`, `422 validation_failed` |
| `GET /api/v1/sync/tombstones?since={cursor}` | Provisional | M2 step 3b (implemented) | The learner's deletion records after a cursor, oldest first, 500 at a time (`next_since`, `more`). Browsers read them before replaying drafts ([ADR 0003 §5.4](../adr/0003-web-workspaces-and-study-content.md#54-deletion-trash-and-redaction-reaching-browsers)) |
| `POST /api/v1/notes` | Draft | Later in M2 | Creating a note from the browser with its own ID (offline creation). Notes are created on the workspace screens until then |

There is no admin JSON API in M1. Admin actions run through Livewire and the console, and both go through the same services.

## Error codes

Stable. New codes may be added; existing codes never change meaning.

| Status | Codes |
|---|---|
| 400 | `bad_request` |
| 401 | `unauthenticated` · `account_deleted` (clients purge that account's drafts) |
| 403 | `forbidden` · `admin_role_required` · `student_role_required` · `two_factor_required` · `access_revoked` (clients stop retrying) · `system_not_allowed` · `recovery_codes_already_shown` |
| 404 | `not_found`: identical for missing records and other learners' records |
| 405 | `method_not_allowed` |
| 409 | `conflict` · `version_conflict` (`details.current_version`) · `id_conflict` · `capture_key_conflict` · `last_admin` · `email_taken` · `target_deleted` (an admin action on an account that was deleted) |
| 410 | `gone` (`details.reason`: `trashed` · `deleted` · `redacted`) |
| 413 | `too_large` |
| 419 | `session_expired` |
| 422 | `validation_failed` (`details.fields`) · `invalid_entry` · `unknown_reference` · `review_not_allowed` · `invitation_invalid` |
| 423 | `password_confirmation_required` |
| 429 | `rate_limited` |
| 500 | `server_error` |
| 503 | `unavailable` · `reconcile_required` (the redaction ledger is ahead of the database) |

## Audit actions

Implemented in increment 2 (`App\Audit\AuditAction`). Provisional until the PM approves the increment, then Stable.

| Action | Target |
|---|---|
| `account.created`, `account.suspended`, `account.reactivated`, `account.deletion_requested` | user |
| `invitation.created`, `invitation.revoked`, `invitation.accepted` | invitation |
| `role.granted`, `role.revoked` (`metadata.role`) | user |
| `two_factor.reset` | user |
| `two_factor.confirmed`, `two_factor.disabled`, `two_factor.recovery_codes_generated`, `two_factor.recovery_code_used` | user |
| `area.admin_entered` (`workspace.admin_entered` before M2 step 0) | user |
| `admin.access_denied` (a non-admin reached an admin route) | route name |
