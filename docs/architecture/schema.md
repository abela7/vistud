# Database schema (M1)

- **Status:** M1, increment 1. Each table is marked **Stable**, **Provisional** or **Draft** ([what the labels mean](contracts.md#stability-levels)).
- **Owner:** the architect. Migrations live in `database/migrations/`.
- **Engine:** MySQL 8.4 LTS, InnoDB, `utf8mb4_unicode_ci`. Local development may use 8.0; CI uses 8.4.

## Rules for every table

- **Times are UTC.** The connection runs at `+00:00`. Journal times use `DATETIME(6)`; framework tables keep Laravel's `TIMESTAMP` columns.
- **IDs.** New IDs are UUIDv7, stored as `CHAR(36)`. Journal IDs are *ID tokens* of up to 64 characters, so fixtures such as `T-LEFT` stay readable ([conventions.md](conventions.md#ids)).
- **Learner data carries `learner_id`** (ADR 0001, day-one rule 2) and is listed in `App\Platform\Database\LearnerTables`. An architecture test checks both.
- **Journal keys are `(learner_id, id)`.** IDs are unique within a learner's stream only. A client that picks an ID already used in another learner's stream gets no different answer from one that picks a fresh ID.
- **Free text lives only in content tables** (`journal_content`), never in metadata columns. No SQL may filter, search or sort on it (ADR 0001, day-one rule 1).
- **Two database users** ([setup.md](../development/setup.md#database-users)). The runtime user gets per-table privileges after every migration: `SELECT, INSERT` on `audit_log`, `SELECT` on `migrations`, and `SELECT, INSERT, UPDATE, DELETE` elsewhere. It can never change the schema.
- **Until M1 is approved,** migrations may be edited in place, because no database has been deployed. After that, only new migrations are added.

## Identity (Stable)

### `users`
A login identity.

| Column | Type | Notes |
|---|---|---|
| `id` | uuid, PK | UUIDv7 |
| `name`, `email` | string | `email` is unique |
| `email_verified_at` | timestamp, null | |
| `password` | string | Hashed |
| `two_factor_secret`, `two_factor_recovery_codes` | text, null | Encrypted by Fortify |
| `two_factor_confirmed_at` | timestamp, null | 2FA counts only once confirmed |
| `status` | string(16) | `active` · `suspended` · `deleted`. Changed only by Identity services |
| `status_changed_at` | timestamp, null | |
| `remember_token`, `created_at`, `updated_at` | | Laravel defaults |

`role` and `status` are deliberately **not fillable**, so no request can set them by mass assignment (ADR 0003 T5).

### `user_roles`

| Column | Type | Notes |
|---|---|---|
| `user_id` | uuid, FK `users` | Cascade on delete |
| `role` | string(16) | `student` · `admin` |
| `granted_at` | timestamp | |
| `granted_by` | uuid, null | Null when granted from the console (a system action) |

Primary key `(user_id, role)`. Revoking a role deletes the row; the history is in `audit_log`.

### `learners`
The learner stream (ADR 0002 §3), created together with the `student` role.

| Column | Type | Notes |
|---|---|---|
| `id` | uuid, PK | The `learner` value in every journal entry |
| `user_id` | uuid, unique, FK `users` | Restrict on delete: erasure removes learner data first |
| `timezone` | string(64) | IANA name, used for day and week precision |
| `journal_position` | unsigned bigint | Last position assigned. The writer locks this row to assign the next |
| `cache_version` | unsigned int | Increased by redaction, so cached briefs become unreachable |

### `invitations`

| Column | Type | Notes |
|---|---|---|
| `id` | uuid, PK | |
| `email` | string | |
| `token_hash` | char(64), unique | SHA-256 of the token. The token is shown once and never stored |
| `invited_by` | uuid, null | |
| `expires_at`, `accepted_at`, `revoked_at` | timestamp | |
| `accepted_user_id` | uuid, null | |

An invitation never carries a role. Accepting one creates a student account.

### Framework tables
`password_reset_tokens`, `sessions` (with `user_id` as uuid), `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`: Laravel defaults.

## Audit (Stable)

### `audit_log`
Append-only (ADR 0003 §10.4). The runtime user can only `SELECT` and `INSERT`. There are no foreign keys, so erasing an account never touches it.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint, PK | Auto-increment, so order is total |
| `occurred_at` | datetime(6) | |
| `actor_type` | string(16) | `user` · `system` |
| `actor_user_id` | uuid, null | |
| `actor_role` | string(16) | `student` · `admin` · `system`: the role the actor was acting in |
| `action` | string(64) | Dotted name, e.g. `role.granted`. The list is in [contracts.md](contracts.md#audit-actions) |
| `target_type`, `target_id` | string, null | |
| `metadata` | json, null | IDs and flags only. **Never content, never email addresses** |
| `ip`, `user_agent`, `request_id` | string, null | |

## Journal (Provisional)

ADR 0002 defines what goes in; these tables define how it's stored.

### `journal_entries`
One row per event, never updated. Corrections are new entries (amendments and reviews).

| Column | Type | From the ADR 0002 envelope |
|---|---|---|
| `learner_id` | uuid, FK `learners` | `learner` |
| `id` | string(64) | `id` |
| `position` | unsigned bigint | `position`, unique per learner, strictly increasing |
| `kind`, `type`, `type_version` | string, string, smallint | `kind`, `type`, `type_version` |
| `actor_type`, `actor_id`, `actor_channel` | string | `actor` |
| `origin` | string, null | `origin` (observations only) |
| `occurred_at`, `occurred_until` | datetime(6) | Occurrence |
| `precision`, `tz` | string | Occurrence precision and time zone |
| `interval_lo`, `interval_hi` | datetime(6) | The occurrence interval, computed at write time (ADR 0002 §3) |
| `recorded_at`, `received_at` | datetime(6) | Device time (untrusted) and server time (trusted) |
| `capture_key` | string(128), null | Unique per learner when present |
| `session_id`, `activity_id` | string(64), null | `session`, `activity` |
| `links` | json | `[{rel, target, role?}]` |
| `body` | json | Kind-specific core fields |
| `content_fields` | json | Names of the content fields written with the entry |
| `fingerprint` | char(64) | SHA-256 of the canonical specification, including content. Same ID with the same fingerprint is a duplicate; with a different one, a conflict |

Primary key `(learner_id, id)`. Unique `(learner_id, position)` and `(learner_id, capture_key)`. Indexes on `(learner_id, kind, interval_lo)` and `(learner_id, session_id)`.

### `journal_content`
`(learner_id, entry_id, field)` → `text` (longtext). The only place free text is stored. Redaction deletes rows here. Encrypting this column is the planned migration before another real learner joins (ADR 0001).

### `journal_mentions`
`(learner_id, entry_id, n)` → `field`, `start`, `end`. Character ranges inside a content field. Deleted together with that field.

### `journal_refs`
A **derived** index of every reference an entry makes, written in the same transaction and rebuildable from `journal_entries`. Columns: `learner_id`, `entry_id`, `role`, `ref_type`, `ref_id`, `locator`. Indexed by `(learner_id, ref_type, ref_id)`. It answers "which entries point at X" without scanning JSON.

| Role | Meaning |
|---|---|
| `link:about`, `link:cites`, `link:responds_to`, `link:triggered_by` | The entry's links |
| `target`, `derived_from`, `supersedes` | A claim's or amendment's references |
| `value` | References inside a claim's value, such as `refers_to`'s entity or a verdict's topics |
| `task` | The task an attempt answers |
| `defines` | The entity a `defines` claim introduces |
| `record` | The entity a task, activity, source, course or module record introduces |

The writer uses the `defines` and `record` rows to check that an entity exists in the learner's own journal before anything references it.

## Derived state (Provisional)

### `projection_snapshots`
`(learner_id, rules_version)` → `position`, `snapshot` (json), `computed_at`. A cache of the projector's output at a journal position. Derived state is never stored as truth (ADR 0002 §1); this table can be emptied at any time. Snapshots hold IDs, labels, states and flags only.

## Redaction, clean-up and files (Draft)

These belong to work package WP5, whose owner may revise them through the contract-change process.

| Table | Purpose |
|---|---|
| `journal_blocks` | Block entries: `(learner_id, entity_type, entity_id)` with `entity_type` `event` or `source`. From the moment a redaction commits, nothing serves a blocked item (ADR 0002 §10) |
| `redactions` | One row per redaction: targets, fields, reason, the redaction-ledger sequence number, and clean-up status (`cleanup_completed_at`, `cleanup_overdue_at`). Never the redacted text |
| `outbox` | The transactional outbox: `task`, `payload` (IDs only), `status` (`pending` · `done`), `attempts`, `next_attempt_at`, `last_error` (a code, never content) |
| `canonical_files` | Canonical source files, stored per learner: `storage_key`, `sha256`, `size`, `media_type`, `visibility` (`private` · `shared`). `learner_id` is null only for shared course material |
| `system_markers` | Named markers, such as the last redaction-ledger entry applied |

The **redaction ledger** itself is deliberately not a table: it lives outside MySQL and the file backups (ADR 0002 §10).

## Study content (Provisional, M2)

### `workspaces`
A learner table (`LearnerTables`): a student's space for one subject ([docs/specs/workspaces.md](../specs/workspaces.md)). `id` (UUIDv7), `learner_id`, `name` (≤ 80), `code` (≤ 20), `term` (≤ 40), `starts_on`, `ends_on`, `colour` (one of the theme's workspace colours), `icon`, `type` (`general` until workspace types arrive), `position`, `revision` (matches the journal record's), `archived_at`, `created_at`, `updated_at`. Each change also appends a revision of the `workspace` journal record.

## Not yet built

### `modules`
A learner table: a unit of a workspace, like "Week 1: Cells". `id`, `learner_id`, `workspace_id`, `title` (≤ 120), `starts_on`, `ends_on`, `position` (1 is first), `revision`, `created_at`, `updated_at`. Each change, reordering included, appends a revision of the `module` journal record; deleting (only when empty) appends one with status `deleted`.

### `folders`
A learner table, for organisation only: never journalled (ADR 0003 §9.2). `id`, `learner_id`, `workspace_id`, `module_id`, `parent_id` (another folder, or null at the top of the module), `name` (≤ 120), `depth` (1 at the top, at most 8), `position` among its siblings, `created_at`, `updated_at`.

Notes, note versions, note blocks, deletion records (`content_tombstones`), files and activities arrive with the next M2 steps ([docs/specs/workspaces.md §8](../specs/workspaces.md#8-for-developers), [ADR 0003 §9](../adr/0003-web-workspaces-and-study-content.md#9-study-content-model)). Encryption columns and the key database arrive before another real learner joins (ADR 0001).
