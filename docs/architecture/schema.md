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
| `key_provisioned_at` | timestamp, null | S1: once set, a missing key is an error; provisioning never replaces it |

### `learner_keys` (S1, separate key database)

Registered separately as `LearnerTables::KEY_TABLES`, queried through the same
isolation layer on `mysql_keys`. Migrations: `database/key-migrations`, run with
`mysql_keys_owner`; never included in the content database's migration path or dumps.
`learner_id` is the UUID primary key (no cross-database foreign key), `key_version`
is the payload/derivation version (1), `master_version` is the SHA-256 identifier of
the wrapping master, `wrapped_key` is Laravel AES-256-GCM ciphertext, plus timestamps.
The authenticated inner payload repeats the learner ID, key version and master
version and contains the random 32-byte data key. All are checked when loaded.
Master secrets are runtime environment values, not database/config-cache fields.

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

### `platform_settings`
Settings of the installation itself, set by an admin on a screen (docs/specs/study-memory.md §6); not a learner table. `key` (PK, ≤ 64: `engine.key`, `engine.url`, `engine.tutor_model`, `engine.reader_model`, `engine.helper_model`; the old `engine.quick_model` was renamed to `engine.reader_model` by the 2026-10-06 migration), `value` (text, nullable; encrypted with the app key when `secret`, and then never shown whole again), `secret`, `updated_by` (the admin's user id, nullable), `updated_at`. Who changed what is in the audit log (`engine.key_set` with the key's last four characters, `engine.key_removed`, `engine.defaults_changed`); the key itself never is.

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
| `outbox` | The transactional outbox (registered in `LearnerTables`, reachable only via `LearnerScope::forJob`): `task`, `payload` (IDs only), `status` (`pending` · `done`), `attempts`, `next_attempt_at`, `last_error` (a code, never content) |
| `canonical_files` | Canonical source files, stored per learner (`LearnerTables`): `storage_key`, `sha256`, `size`, `media_type`, `visibility` (`private` · `shared`). `learner_id` is null only for shared course material, reached only through `LearnerTables::shared('canonical_files')` |
| `system_markers` | Named markers, such as the last redaction-ledger entry applied |

The **redaction ledger** itself is deliberately not a table: it lives outside MySQL and the file backups (ADR 0002 §10).

## Study content (Provisional, M2)

### `workspaces`
A learner table (`LearnerTables`): a student's space for one subject ([docs/specs/workspaces.md](../specs/workspaces.md)). `id` (UUIDv7), `learner_id`, `name` (≤ 80), `code` (≤ 20), `term` (≤ 40), `starts_on`, `ends_on`, `colour` (one of the theme's workspace colours), `icon`, `type` (`general` until workspace types arrive), `project_tools` (bool, off: whether the course shows labels, a team, priorities, the person a task is for, milestones and how an assignment is going; docs/specs/vistud-2-blueprint.md Phase 6), `position`, `revision` (matches the journal record's), `archived_at`, `created_at`, `updated_at`. Each change also appends a revision of the `workspace` journal record.

## Not yet built

### `modules`
A learner table: a unit of a workspace, like "Week 1: Cells". `id`, `learner_id`, `workspace_id`, `title` (≤ 120), `starts_on`, `ends_on`, `position` (1 is first), `revision`, `created_at`, `updated_at`. Each change, reordering included, appends a revision of the `module` journal record; deleting (only when empty) appends one with status `deleted`.

### `folders`
A learner table, for organisation only: never journalled (ADR 0003 §9.2). `id`, `learner_id`, `workspace_id`, `module_id` (null for a folder outside every module, at the workspace's top level), `parent_id` (another folder, or null at the top of its module or workspace), `name` (≤ 120), `depth` (1 at the top, at most 8), `position` among its siblings, `created_at`, `updated_at`.

### `notes`
A learner table ([ADR 0003 §9.1](../adr/0003-web-workspaces-and-study-content.md#91-notes-versions-and-citations-d3--a-with-the-evidence-exception)). `id`, `learner_id`, `workspace_id`, `module_id` and `folder_id` (where it is; both null at the workspace's top level), `create_id` (the editor's own ID for a note made by its first save, unique per learner, so a retry can't make two; null for a note made otherwise), `title` (≤ 200, empty for an untitled note), `current_version`, `position`, `trashed_at` (deleted for good 30 days later by `vistud:trash:purge`, daily), `pinned_at` (when the student pinned it: its button is in the corner of every page; null when not pinned, and trashing clears it; indexed with `learner_id`; at most `Notes::MAX_PINNED` per learner), `created_at`, `updated_at`. Not journalled yet: the note's `source` record arrives with the extractor (ADR 0003 §9.3).

### `note_versions`
A learner table. Every accepted save, never changed: `id`, `learner_id`, `note_id` (FK `notes.id` `ON DELETE CASCADE`, 2026-10-17), `version` (unique per note), `title`, `doc` (the editor's JSON, cleaned by `App\Study\NoteDoc`), `base_version`, `save_id` (unique per note: a retried save answers the same), `client_id` (the browser tab), `kind` (`created`, `autosave`, `conflict_resolution`, or `tutor` for what the chat's tutor added at the end, 2026-10-05), `created_at`. All versions are kept for now; the retention rules of ADR 0003 §9.1 come with version history.

### `content_tombstones`
A learner table of deletion records (ADR 0003 §5.4): `id` (auto-increment, the sync cursor), `learner_id`, `entity_type` (`note`), `entity_id`, `kind` (`trashed`, `restored`, `deleted`), `at`. Written now; browsers read them from step 3b.

### `files`
A learner table (docs/architecture/conventions.md "Uploaded files"). `id`, `learner_id`, `workspace_id`, `module_id` and `folder_id` (where it is; both null at the workspace's top level), `name` (≤ 200, without the extension), `extension`, `kind` (`pdf`, `document`, `slides`, `spreadsheet`, `text`, `image`), `mime` (the type it was checked as), `size`, `sha256`, `storage_key` (where the bytes are on the files disk), `position`, `trashed_at` (deleted for good, bytes included, 30 days later by `vistud:trash:purge`), `created_at`, `updated_at`.

### `topics`
A learner table for the tracker (docs/specs/study-memory.md §3). The row's `id` is the journal topic's id (its `defines` claim). `learner_id`, `workspace_id`, `module_id` (nullable), `name` (≤ 120), `status` (the latest word: `covered`, `understood`, `confused`, or null; the student's is also a journal event, so for it this is a cache), `status_by` (`stated` when the student reports directly via `Topics::report()`, `accepted` when the student accepts an AI proposal via `Topics::accept()`, `tutor` when the built-in tutor marks it, `chat` when an MCP chat client marks it, or `student` on legacy rows from before M6.0 which surface as `stated_unverified` until reviewed by `vistud:topics:attribution-audit`; null with no status; the student's word (`stated`, `accepted`, `student`) wins over an AI mark (`tutor`, `chat`) within the same session, and AI marks only change the row, never journal evidence, and can be undone) and `status_at`, `position`, `retired_at`, `created_at`, `updated_at`. The evidence label and flags are never stored: the screens read them from the projection.

### `questions`
A learner table for the tracker. The row's `id` is the journal question's id. `learner_id`, `workspace_id`, `topic_id` and `module_id` (nullable; the module is the topic's unless given), `text` (≤ 1000), `status` (`pending`, `stuck` or `answered`: the student's word), `answer` (≤ 2000, nullable), `session_id` (the study session it was asked in, nullable), `answered_at`, `ask_event_id` (the `question` event an answer responds to), `ask_teacher`, `retired_at`, `created_at`, `updated_at`. The derived state (open, resolved…) comes from the projection; a question marked understood before statuses existed shows as answered.

### `findings`
A learner table for the tracker: the short "must know" lines pinned to a topic. `id`, `learner_id`, `workspace_id`, `topic_id`, `text` (≤ 500), `source_type` (`note`, `file` or null) and `source_id` (a note or file of the same workspace), `locator` (≤ 60, like "slide 12"; kept only with a source), `author` (`student`, or `ai` when a study session wrote it), `created_at`, `updated_at`. Plain rows, not journal events: they are the student's material, not learning state. A retired topic's findings leave the screens and stay in the table.

### `links`
A learner table: web links kept beside notes and files. `id`, `learner_id`, `workspace_id`, `module_id` and `folder_id` (where it is; both null at the workspace's top level), `create_id` (the editor's own ID for a note made by its first save, unique per learner, so a retry can't make two; null for a note made otherwise), `title` (≤ 200; the site's name when left empty), `url` (≤ 2000, `http` or `https` only), `position`, `created_at`, `updated_at`. A folder or module holding a link isn't empty; moving a folder carries its links.

### `activities`
A learner table: assignments, quizzes, exams, labs, problem sets and other tasks. `id`, `learner_id`, `workspace_id`, `module_id` (nullable), `kind` (the journal's activity kinds; the screens offer all but `lecture`, which comes with the calendar), `title` (≤ 200), `due_on` (a date, nullable), `due_time` (a time on that day in the student's own time zone, nullable: the end of the day when empty), `folder_id` (the assignment's own folder for its files, nullable; kept when the assignment is deleted), `status` (`todo`, `doing`, `done`), `stage` (`understand`, `plan`, `do`, `review`, `submit`, default `understand`; 2026-10-13, Step 2 of the assignment companion: a study aid, not in the journal record), `revision`, `created_at`, `updated_at`. Every change is also a new revision of the `activity` journal record (with `progress` for the status and `status` `active` or `deleted`), like modules. Deleting a module leaves its activities and topics outside every module.

### `activity_items`
A learner table: an assignment's plan (the owner's review, 2026-10-02), one list that fits every kind of assignment, from an essay to a group project. `id`, `learner_id`, `workspace_id`, `activity_id`, `kind` (`part`: a section or a deliverable; `step`: a small thing to do, under a part, under another step (three levels at most, `Plans::MAX_DEPTH`) or on its own; `criterion`: what it is marked on; `milestone`: a day to reach), `parent_id` (the part or step a step belongs to, nullable), `title` (≤ 200), `weight` (the marks as a percentage 1 to 100, nullable; for parts and criteria), `state` (`todo`, `doing`, `stuck` or `done` for a part or a step; `not_yet`, `partly` or `met` for a criterion; `pending` or `achieved` for a milestone), `position` (the order among the items of one kind under one parent), and, from 2026-10-03: `start_on` and `due_on` (dates, nullable; a milestone has only `due_on`), `priority` (`low`, `medium`, `high`, `urgent`, nullable), `notes` (≤ 2000), `labels` (a JSON list of up to 5 words of up to 24 characters), `member_id` (a person of the team, nullable), `folder_id` (a section's own folder, inside the assignment's folder, from 2026-10-04: made the first time a note, a file or a folder is added to the section, renamed with it, removed with it when empty and kept, with what is in it, when not; nullable) and `done_at` (when it was last finished, microseconds, for the burndown and the report to come), plus `created_at`, `updated_at`. At most `Plans::MAX_ITEMS` (200) per assignment. A part or a step with no steps under it is one thing to tick; with steps it is as far as they are (done, stuck, doing or to do). Progress is by marks when every part has some, otherwise by things done. How the work is going (on track, at risk, off track) is worked out from the pace, the stuck steps and what is overdue or missed (`PlanDetails::health`), never stored. Deleting the assignment, or the workspace, deletes its items. A study aid, not evidence: its changes are not journal records.

### `activity_members`
A learner table: the people sharing the work of an assignment, a group project's team (2026-10-03). Names only: they have no accounts, don't see the plan and the student's data stays the student's own. `id`, `learner_id`, `workspace_id`, `activity_id`, `name` (≤ 80), `me` (the student themselves, at most one per assignment), `position`, `created_at`, `updated_at`. At most `Plans::MAX_MEMBERS` (200) per assignment. Taking a person out gives their parts and steps back to nobody. Deleting the assignment, or the workspace, deletes its team.

### `activity_sources`
A learner table: what a student was given about an assignment, besides the files in its folder (the owner's ask, 2026-10-13: the assignment companion's full picture). `id`, `learner_id`, `workspace_id`, `activity_id`, `kind` (`brief`, `rubric`, `email`, `announcement`, `feedback`: what the teacher said, `note`: the student's own, `correction`: what the AI got wrong, or `other`), `title` (≤ 200, nullable), `text` (≤ 60,000 characters, its lines kept), `received_on` (a date, nullable), `created_at`, `updated_at`. At most `AssignmentSources::MAX_SOURCES` (40) per assignment. Deleting the assignment, or the workspace, deletes them. Study material, not evidence: not journal records.

### `activity_understandings`
A learner table: what the tutor's model understands of an assignment from all its sources (2026-10-13), one row per revision, never changed after (except `confirmed_at`), so every model and every later step reads the same picture. `id`, `learner_id`, `workspace_id`, `activity_id`, `revision` (from 1; unique per assignment), `body` (JSON, as `AssignmentUnderstandings::clean` keeps it: `summary`, `goal`, `confidence` and the lists `deliverables`, `requirements`, `constraints`, `criteria`, `teacher_expects`, `best_practices`, `dates`, `modules`, `topics`, `assumptions`, `risks`, `gaps`, `questions`, each item with the `source` it came from: a label below, `course` or `inferred`; modules and topics only the course's own, with their ids), `changes` (≤ 1500, what changed from the revision before and why, nullable), `sources` (JSON: each label it was given, `S1`… for written sources and `F1`… for files, with what it was), `fingerprint` (sha1 of the sources it read; another one now means it is out of date), `model` (≤ 120, who wrote it, nullable), `confirmed_at` (when the student said it is right, nullable), `created_at`. Deleting the assignment, or the workspace, deletes them.

### `activity_log`
A learner table: the log of an assignment's companion chat (2026-10-13: Step 2 of the assignment companion), decisions taken, progress made, advice, feedback, and risks recorded by the tutor or the student. `id` uuid, `learner_id`, `workspace_id`, `activity_id`, `stage` (`understand`, `plan`, `do`, `review`, `submit`), `kind` (`decision`, `progress`, `advice`, `feedback`, `risk`), `text` (≤ 500), `by` (`tutor`, `student`), `created_at` (datetime 6). Indexed `(learner_id, activity_id, created_at)`. At most `AssignmentLog::MAX_ENTRIES` (300) per assignment. Deleting the assignment, or the workspace, deletes its log rows. A study aid, not evidence: not journal records.

### `instructions`
A learner table: what the assistant should know when a study session starts. `id`, `learner_id`, `scope` (`me` for every course, `workspace:{id}` or `module:{id}`; unique per learner), `text` (≤ 2000), `created_at`, `updated_at`. Empty text deletes the row; deleting a module deletes its instructions.

### `study_sessions`
A learner table (docs/specs/study-memory.md §4.1). `id` (also the journal `session` record's id), `learner_id`, `workspace_id`, `module_id` and `topic_id` (nullable), `state` (`running`, `paused`, `break`, `ended`; at most one not ended per learner, kept by the database: `open_learner` is a stored column, the learner's id while the session isn't ended and null after, with a unique index), `paused_by` (`student`, `away` or `long_break`), `manual` (logged afterwards), `started_at`, `ended_at`, `last_activity_at` (the heartbeat), `study_seconds` and `break_seconds` (totals of the closed segments; the open one is added when read), `pomodoro` (JSON `{focus, short, long, every, auto}`, minutes; null on the free clock), `phase` (`focus`, `short_break`, `long_break`) and `phase_started_at`, `pomodoros` (focus periods finished) and `pomodoros_skipped`, `tutoring` (JSON `{method, check_ins, quiz, pace}`, App\Study\Tutoring), `material` (JSON list of `note:{id}` and `file:{id}` of the workspace, or null), `summary` and `checkpoint` (the tutor's, saved by the write-back), `captured` (JSON list of the fingerprints of marks already saved), `note_id` (the session note), `revision`, `created_at`, `updated_at`. `paused_by` can also be `pomodoro`: a break ended and the next focus waits for the student. `mode` (`module`, `topic`, `quiz`, `test` or `free`; 2026-10-07, docs/specs/vistud-2-blueprint.md §3.9: what the session is for, said to the tutor in one line; not given, a module alone means `module` and anything else `topic`; `free` is only ever asked for; a logged session is `topic`).

### `session_segments`
A learner table: the stretches a session is made of. `id`, `learner_id`, `session_id` (FK `study_sessions.id` `ON DELETE CASCADE`, 2026-10-17), `kind` (`study` or `break`), `started_at`, `ended_at` (null while open; at most one open per session), `ended_by` (`pause`, `break`, `resume`, `end`, `away`, `long_break`, `log`, `pomodoro` or `skip`). A gap between segments is a pause.

### `flashcards`
A learner table (docs/specs/study-memory.md §4.5): `id`, `learner_id`, `workspace_id`, `topic_id` (nullable), `module_id` (nullable: the topic's module when the topic has one, else the module chosen for it, else the module of the session it was made in; kept when its topic moves, emptied when the module is deleted), `front` (≤ 500), `back` (≤ 1000), `revision` (its journal task's content revision), `author` (`student` or `ai`), `session_id` (the session it came from, if any), `step` (its rung on the ladder of gaps, 0 for a new or missed card), `due_on` (the student's date it's next due; null for a new card, due now), `reviews`, `lapses`, `last_result` (`correct`, `partial` or `incorrect`), `last_reviewed_at`, `retired_at`, `created_at`, `updated_at`. Each card is also a `task` record (`card-{id}`) in the journal, and each answer an `attempt`.

### `engine_settings`
A learner table (docs/specs/study-memory.md §6): a student's choices for the built-in chat's engine. `id`, `learner_id` (unique), `tutor_model`, `reader_model`, `helper_model` and `fallback_model` (ids as the service names them, ≤ 120, nullable until chosen; the owner's defaults apply meanwhile; an empty role uses the next one up, `Choices::modelFor`; `reader_model` is the old `quick_model`, renamed with its values kept), `session_cap_micros` and `month_cap_micros` (the most a session's chat and a month's chats may cost, in millionths of a dollar; 0 for no limit), `no_training` (keep the student's words out of model training; on), `language` (the language the tutor teaches in, up to 40 characters; null for the one the student writes in; 2026-10-05), `ask_topics` (whether the tutor asks before adding or switching topics; off, so it keeps them itself; 2026-10-06), `auto_read_files` (read files on upload; on), `tutor_marks_topics` (the tutor may mark topics, with undo; on), `tutor_remembers` (the tutor may keep short notes about the student, `tutor_memories`; on; 2026-10-12) and `copy_paste_ai` (show the copy-and-paste tools for another AI; off), the trust toggles of ViStud 2 (2026-10-06; the last three only record the choice until Phases 2 and 3), `consented_at` (when the student agreed to the chat sending their study material to the provider; null withdraws it), `key_encrypted` (the student's own key for the service, encrypted with the app key and shown again only by its last four characters; null to use the key an admin set up for everyone; 2026-10-05) and `key_updated_at`, `created_at`, `updated_at`.

### `engine_threads`
A learner table: the built-in chat of one study session. `id`, `learner_id`, `workspace_id`, `session_id` (unique per learner), `note_id` (the session's study note the tutor writes in, once it has started one; nullable), `model` (the tutor model last used), `summary` (the oldest turns folded into a few lines by the quick model, nullable) and `folded_through` (the message position the summary stands for; messages up to it are kept but not sent), `spent_micros` (what the chat has cost, the folds and the wrap-up included), `turns`, `wrapped_at` (when the session's summary and checkpoint were written from the chat at its end, nullable), `created_at`, `updated_at`. The chat is a study aid kept to be read again and saved from (the write-back); the memory stays the journal.

Since 2026-10-12 a thread is one of four: a session's (`session_id`), a topic's chat (`topic_id`, no session), one of ViStud AI's conversations (`purpose` `vistud`: no course, so `workspace_id` is nullable, no session and no topic; `title` is its first words, for the list), or an assignment companion's (`activity_id`, `purpose` `assignment`; 2026-10-13, Step 2: one thread per assignment, indexed by `learner_id` and `activity_id`). ViStud AI (`App\Engine\VistudAi`) is the student's assistant above all their courses, on the main dashboard only: its own rules (`resources/prompts/vistud-ai.md`), its own tools (`App\Engine\Vistud\VistudToolbox`: across courses, and the tutor's course tools with a course to name), and a bounded snapshot of the whole study each call (`App\Engine\Vistud\Overview`: at most 12 courses, 8 deadlines and 5 sessions, the rest counted). Indexed by learner, purpose and time for the list.

### `course_profiles`
A learner table (docs/specs/vistud-2-blueprint.md §3.5.2, §3.7; 2026-10-07): what the AI knows about a course, one row per course (unique with `learner_id` and `workspace_id`), made when first written. `id`, `learner_id`, `workspace_id`, `about` (text, up to 1,200 characters), `outcomes` (JSON list of up to 12 lines), `assessment` (JSON list of up to 12 `{name, kind, weight, due_on, activity_id}`: `kind` is one of the assignment kinds, `weight` a percent, `due_on` a day, `activity_id` the assignment made from it so adding again never doubles it), `textbook` (≤ 200), `syllabus_file_id` (the file of the course the reader is to read) and `syllabus_text` (the pasted text, cleared once read), `proposed_modules` (JSON `[{title, starts_on, ends_on}]`: what the reader found, waiting for the student to tick; null once added or dismissed), `source` (`manual` or `file`), `model` (the reader's model that built it), `built_at`, `created_at`, `updated_at`. Written by the student (`App\Study\CourseProfiles::save`) or by the reader's `profile_course` job; the tutor reads it as its course layer. Deleted with the course.

### `learner_profiles`
A learner table (same sources; 2026-10-07): how a student likes to learn in one course, one row per course. `id`, `learner_id`, `workspace_id`, `preferences` (JSON `{explain: [examples|theory|analogies|diagrams], pace: small|normal|fast, check: often|end|rarely, goal: pass|top|deep|coursework}`, every part optional), `note` (≤ 300 characters, in the student's words), `created_at`, `updated_at`. The tutor is told it as one short line, and it is the default for how a session teaches until the student chooses otherwise (`App\Study\LearnerProfiles::teaching`). Never a label on the student: it records how they like things *explained*, not a type. Deleted with the course.

### `tutor_memories`
A learner table (2026-10-12): what the tutor remembers about its student, so what they said about themselves ("anxious about maths, build from the basics") isn't lost when a chat ends. `id`, `learner_id`, `workspace_id` (null: for every course), `text` (one line, ≤ 160 characters, spaces folded), `by` (`tutor` or `student`), `session_id` and `topic_id` (where the tutor kept it: a session, or a topic's chat; nullable), `created_at`, `updated_at`. At most 8 for all courses and 8 for each course, so they always fit what the tutor reads; when full, one is replaced or deleted first. The tutor keeps them with the `remember` tool (only while `engine_settings.tutor_remembers` is on), sees them numbered at the start of every chat (the student layer, never cut) and replaces one by its `id` (or in-app by its number); a tutor without tools, or another AI by copy-paste, writes a `<remember>` mark (`for="all"` for every course) that is kept only if the student ticks it in the end-of-session review; the copy-paste briefing lists them under *About the student*, and the helper is told those for every course; the student sees, changes, deletes and adds them in *Instructions for the AI* (`App\Study\TutorMemories`). A course's are deleted with the course; those for every course stay.

### `engine_jobs`
A learner table (docs/specs/vistud-2-blueprint.md §3.6.3): one row for each run of the reader or the helper, so what they cost counts and a failure is not silent. `id`, `learner_id`, `workspace_id` (nullable), `role` (`reader` or `helper`, ≤ 10; for *Usage this month*), `kind` (≤ 40: `wrap_up`, `fold`, `quick`; later `read_file` and the like), `target_type` and `target_id` (what it was about: a session, a thread, a file; nullable), `status` (`queued`, `running`, `done`, `skipped` or `failed`), `model` (the one that answered, nullable), `tokens_in`, `tokens_cached` (of those, read from the service's prompt cache, 2026-10-19), `tokens_out`, `cost_micros` (what the service said, summed over the run's calls), `error_code` (≤ 60, nullable: a stable code, never the service's words), `attempts`, `started_at`, `finished_at` and `created_at`. Indexed by learner and time, and by learner and target. A job runs where a queue worker is alive (`cache('engine.worker_seen_at')`, written by the worker and fresh within 60 seconds), after the response where none is, and at once in tests; it carries only ids. The tutor's chat is not a job: its cost stays on `engine_messages`.

### `file_digests`
A learner table (docs/specs/vistud-2-blueprint.md §3.5.3, §3.7; 2026-10-07): what the reader wrote about a file, for the file's content as it was read. `id`, `learner_id`, `file_id`, `content_hash` (the file's `sha256`: the same bytes are read once, a changed file is read again; unique with `learner_id` and `file_id`), `status` (`done`, or `skipped` when there was nothing to read), `reason` (≤ 30, for `skipped`: `picture`, `no_text`…), `summary` (≤ 600 characters), `outline` (JSON, up to 40 `{page, heading}`), `topics` (JSON, up to 8 names), `language`, `pages`, `chars` (how much of the file was read), `model`, `cost_micros` and `created_at`. A file is *read* (a `done` digest whose hash is the file's now), *reading* (an `engine_jobs` row of kind `read_file` for it is queued or running and no older than 15 minutes), *skipped*, or *unread*. Reading a file whose bytes were read before in another file of the student's copies that digest at no cost (`FileDigests::reuse`). Deleted with the file (and the course).

### `topic_suggestions`
A learner table (same sources): topics the reader found in a module's files, until the student acts. `id`, `learner_id`, `module_id`, `name` (≤ 120), `name_key` (the name lower-cased, with `learner_id` and `module_id` unique: a module is suggested a topic once whatever the file says; an addition beyond the blueprint's table), `source_file_id` (nullable), `status` (`suggested`, `added` or `dismissed`) and `created_at`. A name the module already has as a topic is never suggested; a dismissed one is not suggested again. Deleted with the module.

### `module_briefs`
A learner table (same sources): the module layer of the tutor's context (layer 4), built once and kept. `module_id` (primary key), `learner_id`, `text`, `fingerprint` (sha1 of what it was built from: the title and dates, the topics and their statuses, the files and their digests, the open questions, the key points, the last session's checkpoint) and `built_at`. It is rebuilt whenever the fingerprint of its inputs changes, so nothing has to remember to invalidate it. Deleted with the module.

### `quizzes`
A learner table (docs/specs/vistud-2-blueprint.md §3.7, §3.9; 2026-10-07): a quiz or test the tutor asked and recorded with `record_quiz`, so it can be read back and the progress screens can say "tested 90 %". `id`, `learner_id`, `workspace_id`, `session_id`, `module_id`, `topic_id` (all nullable: a deleted session, module or topic leaves the record), `kind` (`quiz`, about five questions on a topic; or `test`, ten to fifteen over a module), `questions` (JSON, at most 20: `asked`, `answer`, `result` `correct`/`partial`/`incorrect`, `right`, `fix`, `topic` and `topic_id`), `score` (0 to 100: a correct answer counts one, a partial one half; worked out from the results, not given by the tutor), `asked`, `started_at`, `finished_at`. Each answer with a topic is also a journal attempt (through the write-back), which is what mastery counts. A test's per-topic scores propose statuses: 80 % or more understood, 50 % or less still confusing, otherwise covered.

### `engine_messages`
A learner table: the turns of a thread, in order. `id`, `learner_id`, `thread_id` (FK `engine_threads.id` `ON DELETE CASCADE`, 2026-10-17), `position` (unique per thread), `role` (`user`, `assistant` or `tool`), `content` (the words; a tool's result for `tool`), `attachments` (JSON, the notes and files the student attached to a `user` message: each one's reference, name, kind, and the text that went with it to the engine, nullable), `tool_calls` (JSON, the look-ups an assistant turn asked for, in the OpenAI shape, nullable), `reasoning` (JSON, the model's reasoning as the service handed it back, sent back unchanged with the message to the same model, since some refuse a look-up's result without it; nullable), `tool_call_id` and `tool_name` (for a `tool` row), `effects` (JSON, for a `tool` row: what it did in the course, how many flashcards, key points, questions and topics it saved, the notes it wrote in with their versions, and the topic it gave the session; nullable), `model` (the one that answered, nullable), `tokens_in`, `tokens_cached` (of those, read from the service's prompt cache, 2026-10-19), `tokens_out`, `cost_micros` (what the service said the call cost), `created_at`. The student's name and email are never in a message ViStud writes.

### `oauth_clients`, `oauth_auth_codes`, `oauth_access_tokens`, `oauth_refresh_tokens`
Passport's tables for AI apps that sign in over MCP (M6.1, migration `2026_10_18_100000_create_oauth_tables`). Not learner tables: they hold tokens, never study data. They are Passport's own, with `user_id` as a UUID (ViStud's users have UUIDs) and the client owner as a UUID morph. `oauth_clients` rows are made by dynamic client registration (`POST /oauth/register`), always public (no secret) and only for redirect hosts in `config/mcp.php`. Tokens last an hour, refresh tokens 30 days; `passport:purge` runs hourly. The device-code grant is off, so its table is not made.

### `mcp_connections`
A learner table (M6.1, docs/specs/mcp-connections-plan.md §3.4): an AI app the student approved on ViStud's consent page. `id`, `learner_id`, `oauth_client_id` (unique per learner), `kind` (`mcp`), `provider` (`anthropic`, `openai`, `google`, `local` or `other`, fixed at consent from the redirect host), `client_label` (what the app called itself; untrusted, at most 128 characters), `redirect_host`, `level` (`read`, `write` or `full`), `consented_at`, `revoked_at` and `revoked_reason` (`student`, `account`, `two_factor_reset`, `password`, `unused`), `last_used_at` (at most once a minute), `created_at`, `updated_at`. A token whose app has no row with `revoked_at` null is refused (`403 connection_not_consented`). Unused for 90 days, it is cut off at night (`vistud:mcp:prune`).

### `mcp_calls`
A learner table (M6.1; written from M6.2): what each MCP call did, without its text. `id`, `learner_id`, `connection_id` (FK `mcp_connections.id` `ON DELETE CASCADE`), `tool`, `outcome`, `target_type`, `target_id`, `chars`, `effects` (JSON: kinds, counts, ids and status codes only), `created_at`. Kept 400 days.

### `mcp_confirmations`
A learner table (M6.1; used from M6.5): a change an AI app asked for that the student must confirm on a ViStud page. `id`, `learner_id`, `connection_id` (FK `ON DELETE CASCADE`), `tool`, `target_id`, `input` (JSON), `input_hash`, `expires_at`, `used_at`, `created_at`. Cutting a connection deletes its open rows.

Note blocks arrive with the next M2 steps ([docs/specs/workspaces.md §8](../specs/workspaces.md#8-for-developers), [ADR 0003 §9](../adr/0003-web-workspaces-and-study-content.md#9-study-content-model)).

### Core text encryption (S2a)

The exact table/column and original-type manifest is
`App\Platform\Database\ContentColumns::S2A`. Those columns now hold AES-256-GCM
envelopes in LONGTEXT, with `content_version` (unsigned tinyint, default 0 for
existing rows; runtime writes 1). The logical values above, including JSON
objects, remain unchanged when read through services. No content SQL searches,
sorts, JSON extraction, grouping or aggregates are supported.

LONGTEXT is deliberate: Laravel base64-encodes a JSON envelope containing base64
ciphertext, giving roughly 16/9 growth before envelope/JSON escaping overhead.
The inner authenticated payload also contains row metadata; quotes/backslashes
in JSON documents expand further. A near-2,000,000-byte note exceeds TEXT and can
exceed 3 MB encrypted. Original MEDIUMTEXT capacity also needs LONGTEXT after
encryption. LONGTEXT has a 4 GiB storage ceiling; application limits and MySQL's
`max_allowed_packet` still apply. Services retain their existing input limits.

`journal_refs` and `outbox` also have nullable, unique `content_identity` CHAR(36):
null only in unconverted rows, otherwise an immutable UUID reserved before INSERT.
Their numeric keys remain unchanged. Other tables bind their existing physical
keys, including composite journal-content and snapshot keys. The explicit
backfill converts/verifies each row atomically as the owner. Schema rollback
requires the verified offline decrypt procedure in deploy.md first.

### Remaining personal text (S2b)

`ContentColumns::S2B` lists course, module, folder, file and topic names; links;
instructions; assignment titles, plans, team names, sources, understandings and
logs; course and learner profiles; file digests, topic suggestions, module/folder
briefs; quiz questions; and `engine_settings.language`. The same LONGTEXT format,
row version, authenticated identities and query restrictions apply. Module and
folder briefs bind `module_id` and `folder_id` respectively. Both migrations own
frozen original-type lists; changes to the runtime registry cannot change old DDL.

`topic_suggestions.name_key` retains its existing unique index with learner and
module IDs, but holds a 64-character HMAC-SHA256 using the learner's HKDF search
key, a purpose label and the existing PHP lowercase/120-character normalization.
Writes always derive it from the decrypted name, including renames and moves.
Backfill verification checks it; offline decryption restores the normalized name.
It allows exact duplicate checks within a learner, not general text search. File
source names and dismissed suggestions are sorted with a root PRIMARY Collator
in PHP after scoped decryption, with stable ID ties.

S3 still adds file encryption. Admission remains closed until all later checks pass.

## Account erasure (S4a; ADR 0004)

`erasure_requests` is durable control metadata: UUID `id`, unique `user_id`,
nullable indexed `learner_ref`, `requested_at`, indexed `not_before`, `stage`,
nullable fixed `error_code` and `completed_at`. Runtime has SELECT/INSERT only;
progress updates use the owner connection. No identity foreign keys.

`erasure_log` has primary `request_id`, unique `user_id`, nullable indexed
`learner_ref`, `requested_at`, `erased_at` and version (1). Runtime is SELECT-only;
the privileged worker inserts it in the completion transaction. UUID references
survive erased identities for S4b restore replay. Neither table contains content,
email, name, IP, user agent, paths or free-form errors. Schema rollback refuses
nonempty tables to avoid destroying restore protection.

Audit schema-contract exception: the privileged erasure worker may anonymise
identifying fields and associated metadata in existing `audit_log` rows, retaining
every event/action/time/role. Runtime retains SELECT/INSERT only. See ADR 0004.
