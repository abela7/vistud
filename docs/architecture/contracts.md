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
- **Records** carry `body.record_type` and `body.record_id`. A revision appends a new record entry with the same `record_id`.
- **Claims** carry `body.type`, `targets`, `value`, `confidence`, `method {kind, id, version, prompt?}`, `derived_from`, `supersedes` and `review {state, by?}`.
- **References** are `type:id`, optionally `#locator`, for example `source:NOTE#v12/blk-7f3` ([ADR 0002 §5](../adr/0002-learning-event-schema.md#5-claim-contracts)).

Increment 3 brings the vocabulary in `Journal\Vocabulary` and `EntryValidator` up to the latest ADR 0002 revision (`course` and `module` records, `task_revision`, and `checker` for `judged_by: auto`). Those are additions, so the format stays Stable.

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

### Identity and audit (increment 2)

| Service | Methods | Checks |
|---|---|---|
| `Identity\PrincipalFactory` | `fromRequest(Request): Principal` · `forUser(User, channel, ?passwordConfirmedAt): Principal` | Refuses suspended (`access_revoked`) and deleted (`account_deleted`) accounts |
| `Identity\Accounts` | `create(Principal, name, email, password, student: true, timezone?)` · `suspend(Principal, userId)` · `reactivate(Principal, userId)` · `requestDeletion(Principal, userId)` · `details(Principal, userId): AccountDetails` · `list(Principal, cursor?)` | Protected admin, or system for `create`. `requestDeletion` also allows the account's own user, with a recent password confirmation. The last-admin safeguard applies to suspend and delete |
| `Identity\Roles` | `grantAdmin(Principal, userId)` · `revokeAdmin(Principal, userId)` | Protected admin; system may grant. Last-admin safeguard on revoke |
| `Identity\Invitations` | `invite(Principal, email): IssuedInvitation` · `revoke(Principal, invitationId)` · `accept(token, name, password): User` | Protected admin to invite and revoke. `accept` takes no role and always creates a student |
| `Identity\TwoFactorReset` | `reset(Principal, userId)` | Protected admin, or system from the console |
| `Identity\Workspaces` | `enter(Principal, Workspace)` | `admin`: protected admin. `student`: the student role |
| `Audit\AuditLog` | `record(Principal, action, targetType?, targetId?, metadata = [])` · `list(Principal, filters, cursor?)` | `list` needs an admin with 2FA |

`AccountDetails` holds email, dates, status, roles and counts. **No admin service has a method that returns learning content** (ADR 0003 §10.4).

### Journal (increment 3)

| Service | Methods |
|---|---|
| `Brain\Writer\JournalWriter` | `append(LearnerScope, array $spec): AppendResult` · `appendBatch(LearnerScope, list $specs): list<AppendResult>` (atomic). `AppendResult` has `status` (`recorded` · `duplicate`), the stored `JournalEntry` and its `position` |
| `Brain\Store\JournalReader` | `find(LearnerScope, id): ?StoredEntry` · `entries(LearnerScope, ?upToPosition): list<JournalEntry>` · `content(LearnerScope, entryId): array` (respects block entries) |
| `Brain\Projection\ProjectionRunner` | `project(LearnerScope, ProjectionOptions): array`, with the snapshot cache |

The writer trusts nothing about the actor or learner from outside: adapters set the actor from the `Principal`. It refuses entries that break ADR 0002 contracts (`invalid_entry`), references that don't resolve within the learner's own stream (`unknown_reference`, identical for missing and other learners' IDs), and reviews that ADR 0002 §6 forbids (`review_not_allowed`).

## HTTP API

Conventions are in [conventions.md](conventions.md#json-api). An OpenAPI definition (`docs/api/openapi.yaml`) starts with the first endpoint, with contract tests against it (ADR 0003 §8).

| Endpoint | Status | Arrives | Purpose |
|---|---|---|---|
| `GET /api/v1/me` | Provisional | Increment 2 | The account, its roles, its learner ID and the active workspace |
| `GET /api/v1/journal/entries/{id}` | Provisional | Increment 3 | One of the learner's own entries, with content unless blocked. Another learner's ID gets 404 |
| `POST /api/v1/notes`, `PUT /api/v1/notes/{id}`, `GET /api/v1/sync/tombstones` | Draft | M2 | Note sync and deletion records ([ADR 0003 §5.3–5.4](../adr/0003-web-workspaces-and-study-content.md#53-autosave-drafts-ordering-and-conflicts)). Not built in M1 |

There is no admin JSON API in M1. Admin actions run through Livewire and the console, and both go through the same services.

## Error codes

Stable. New codes may be added; existing codes never change meaning.

| Status | Codes |
|---|---|
| 400 | `bad_request` |
| 401 | `unauthenticated` · `account_deleted` (clients purge that account's drafts) |
| 403 | `forbidden` · `admin_role_required` · `student_role_required` · `two_factor_required` · `access_revoked` (clients stop retrying) · `system_not_allowed` |
| 404 | `not_found`: identical for missing records and other learners' records |
| 405 | `method_not_allowed` |
| 409 | `conflict` · `version_conflict` (`details.current_version`) · `id_conflict` · `capture_key_conflict` · `last_admin` |
| 410 | `gone` (`details.reason`: `trashed` · `deleted` · `redacted`) |
| 413 | `too_large` |
| 419 | `session_expired` |
| 422 | `validation_failed` (`details.fields`) · `invalid_entry` · `unknown_reference` · `review_not_allowed` · `invitation_invalid` |
| 423 | `password_confirmation_required` |
| 429 | `rate_limited` |
| 500 | `server_error` |
| 503 | `unavailable` · `reconcile_required` (the redaction ledger is ahead of the database) |

## Audit actions

Provisional names; they become Stable with increment 2.

| Action | Target |
|---|---|
| `account.created`, `account.suspended`, `account.reactivated`, `account.deletion_requested` | user |
| `invitation.created`, `invitation.revoked`, `invitation.accepted` | invitation |
| `role.granted`, `role.revoked` (`metadata.role`) | user |
| `two_factor.reset` | user |
| `workspace.admin_entered` | user |
| `admin.access_denied` (a non-admin reached an admin route) | route name |
