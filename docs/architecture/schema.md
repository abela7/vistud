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

### `platform_settings`
Settings of the installation itself, set by an admin on a screen (docs/specs/study-memory.md §6); not a learner table. `key` (PK, ≤ 64: `engine.key`, `engine.url`, `engine.tutor_model`, `engine.quick_model`), `value` (text, nullable; encrypted with the app key when `secret`, and then never shown whole again), `secret`, `updated_by` (the admin's user id, nullable), `updated_at`. Who changed what is in the audit log (`engine.key_set` with the key's last four characters, `engine.key_removed`, `engine.defaults_changed`); the key itself never is.

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
A learner table, for organisation only: never journalled (ADR 0003 §9.2). `id`, `learner_id`, `workspace_id`, `module_id` (null for a folder outside every module, at the workspace's top level), `parent_id` (another folder, or null at the top of its module or workspace), `name` (≤ 120), `depth` (1 at the top, at most 8), `position` among its siblings, `created_at`, `updated_at`.

### `notes`
A learner table ([ADR 0003 §9.1](../adr/0003-web-workspaces-and-study-content.md#91-notes-versions-and-citations-d3--a-with-the-evidence-exception)). `id`, `learner_id`, `workspace_id`, `module_id` and `folder_id` (where it is; both null at the workspace's top level), `create_id` (the editor's own ID for a note made by its first save, unique per learner, so a retry can't make two; null for a note made otherwise), `title` (≤ 200, empty for an untitled note), `current_version`, `position`, `trashed_at` (deleted for good 30 days later by `vistud:trash:purge`, daily), `pinned_at` (when the student pinned it: its button is in the corner of every page; null when not pinned, and trashing clears it; indexed with `learner_id`; at most `Notes::MAX_PINNED` per learner), `created_at`, `updated_at`. Not journalled yet: the note's `source` record arrives with the extractor (ADR 0003 §9.3).

### `note_versions`
A learner table. Every accepted save, never changed: `id`, `learner_id`, `note_id`, `version` (unique per note), `title`, `doc` (the editor's JSON, cleaned by `App\Study\NoteDoc`), `base_version`, `save_id` (unique per note: a retried save answers the same), `client_id` (the browser tab), `kind` (`created`, `autosave`, `conflict_resolution`, or `tutor` for what the chat's tutor added at the end, 2026-10-05), `created_at`. All versions are kept for now; the retention rules of ADR 0003 §9.1 come with version history.

### `content_tombstones`
A learner table of deletion records (ADR 0003 §5.4): `id` (auto-increment, the sync cursor), `learner_id`, `entity_type` (`note`), `entity_id`, `kind` (`trashed`, `restored`, `deleted`), `at`. Written now; browsers read them from step 3b.

### `files`
A learner table (docs/architecture/conventions.md "Uploaded files"). `id`, `learner_id`, `workspace_id`, `module_id` and `folder_id` (where it is; both null at the workspace's top level), `name` (≤ 200, without the extension), `extension`, `kind` (`pdf`, `document`, `slides`, `spreadsheet`, `text`, `image`), `mime` (the type it was checked as), `size`, `sha256`, `storage_key` (where the bytes are on the files disk), `position`, `trashed_at` (deleted for good, bytes included, 30 days later by `vistud:trash:purge`), `created_at`, `updated_at`.

### `topics`
A learner table for the tracker (docs/specs/study-memory.md §3). The row's `id` is the journal topic's id (its `defines` claim). `learner_id`, `workspace_id`, `module_id` (nullable), `name` (≤ 120), `status` (the student's latest word: `covered`, `understood`, `confused`, or null; each is also a journal event, so this is a cache), `position`, `retired_at`, `created_at`, `updated_at`. The evidence label and flags are never stored: the screens read them from the projection.

### `questions`
A learner table for the tracker. The row's `id` is the journal question's id. `learner_id`, `workspace_id`, `topic_id` and `module_id` (nullable; the module is the topic's unless given), `text` (≤ 1000), `status` (`pending`, `stuck` or `answered`: the student's word), `answer` (≤ 2000, nullable), `session_id` (the study session it was asked in, nullable), `answered_at`, `ask_event_id` (the `question` event an answer responds to), `ask_teacher`, `retired_at`, `created_at`, `updated_at`. The derived state (open, resolved…) comes from the projection; a question marked understood before statuses existed shows as answered.

### `findings`
A learner table for the tracker: the short "must know" lines pinned to a topic. `id`, `learner_id`, `workspace_id`, `topic_id`, `text` (≤ 500), `source_type` (`note`, `file` or null) and `source_id` (a note or file of the same workspace), `locator` (≤ 60, like "slide 12"; kept only with a source), `author` (`student`, or `ai` when a study session wrote it), `created_at`, `updated_at`. Plain rows, not journal events: they are the student's material, not learning state. A retired topic's findings leave the screens and stay in the table.

### `links`
A learner table: web links kept beside notes and files. `id`, `learner_id`, `workspace_id`, `module_id` and `folder_id` (where it is; both null at the workspace's top level), `create_id` (the editor's own ID for a note made by its first save, unique per learner, so a retry can't make two; null for a note made otherwise), `title` (≤ 200; the site's name when left empty), `url` (≤ 2000, `http` or `https` only), `position`, `created_at`, `updated_at`. A folder or module holding a link isn't empty; moving a folder carries its links.

### `activities`
A learner table: assignments, quizzes, exams, labs, problem sets and other tasks. `id`, `learner_id`, `workspace_id`, `module_id` (nullable), `kind` (the journal's activity kinds; the screens offer all but `lecture`, which comes with the calendar), `title` (≤ 200), `due_on` (a date, nullable), `due_time` (a time on that day in the student's own time zone, nullable: the end of the day when empty), `folder_id` (the assignment's own folder for its files, nullable; kept when the assignment is deleted), `status` (`todo`, `doing`, `done`), `revision`, `created_at`, `updated_at`. Every change is also a new revision of the `activity` journal record (with `progress` for the status and `status` `active` or `deleted`), like modules. Deleting a module leaves its activities and topics outside every module.

### `activity_items`
A learner table: an assignment's plan (the owner's review, 2026-10-02), one list that fits every kind of assignment, from an essay to a group project. `id`, `learner_id`, `workspace_id`, `activity_id`, `kind` (`part`: a section or a deliverable; `step`: a small thing to do, under a part, under another step (three levels at most, `Plans::MAX_DEPTH`) or on its own; `criterion`: what it is marked on; `milestone`: a day to reach), `parent_id` (the part or step a step belongs to, nullable), `title` (≤ 200), `weight` (the marks as a percentage 1 to 100, nullable; for parts and criteria), `state` (`todo`, `doing`, `stuck` or `done` for a part or a step; `not_yet`, `partly` or `met` for a criterion; `pending` or `achieved` for a milestone), `position` (the order among the items of one kind under one parent), and, from 2026-10-03: `start_on` and `due_on` (dates, nullable; a milestone has only `due_on`), `priority` (`low`, `medium`, `high`, `urgent`, nullable), `notes` (≤ 2000), `labels` (a JSON list of up to 5 words of up to 24 characters), `member_id` (a person of the team, nullable), `folder_id` (a section's own folder, inside the assignment's folder, from 2026-10-04: made the first time a note, a file or a folder is added to the section, renamed with it, removed with it when empty and kept, with what is in it, when not; nullable) and `done_at` (when it was last finished, microseconds, for the burndown and the report to come), plus `created_at`, `updated_at`. At most `Plans::MAX_ITEMS` (200) per assignment. A part or a step with no steps under it is one thing to tick; with steps it is as far as they are (done, stuck, doing or to do). Progress is by marks when every part has some, otherwise by things done. How the work is going (on track, at risk, off track) is worked out from the pace, the stuck steps and what is overdue or missed (`PlanDetails::health`), never stored. Deleting the assignment, or the workspace, deletes its items. A study aid, not evidence: its changes are not journal records.

### `activity_members`
A learner table: the people sharing the work of an assignment, a group project's team (2026-10-03). Names only: they have no accounts, don't see the plan and the student's data stays the student's own. `id`, `learner_id`, `workspace_id`, `activity_id`, `name` (≤ 80), `me` (the student themselves, at most one per assignment), `position`, `created_at`, `updated_at`. At most `Plans::MAX_MEMBERS` (20) per assignment. Taking a person out gives their parts and steps back to nobody. Deleting the assignment, or the workspace, deletes its team.

### `instructions`
A learner table: what the assistant should know when a study session starts. `id`, `learner_id`, `scope` (`me` for every course, `workspace:{id}` or `module:{id}`; unique per learner), `text` (≤ 2000), `created_at`, `updated_at`. Empty text deletes the row; deleting a module deletes its instructions.

### `study_sessions`
A learner table (docs/specs/study-memory.md §4.1). `id` (also the journal `session` record's id), `learner_id`, `workspace_id`, `module_id` and `topic_id` (nullable), `state` (`running`, `paused`, `break`, `ended`; at most one not ended per learner, kept by the database: `open_learner` is a stored column, the learner's id while the session isn't ended and null after, with a unique index), `paused_by` (`student`, `away` or `long_break`), `manual` (logged afterwards), `started_at`, `ended_at`, `last_activity_at` (the heartbeat), `study_seconds` and `break_seconds` (totals of the closed segments; the open one is added when read), `pomodoro` (JSON `{focus, short, long, every, auto}`, minutes; null on the free clock), `phase` (`focus`, `short_break`, `long_break`) and `phase_started_at`, `pomodoros` (focus periods finished) and `pomodoros_skipped`, `tutoring` (JSON `{method, check_ins, quiz, pace}`, App\Study\Tutoring), `material` (JSON list of `note:{id}` and `file:{id}` of the workspace, or null), `summary` and `checkpoint` (the tutor's, saved by the write-back), `captured` (JSON list of the fingerprints of marks already saved), `note_id` (the session note), `revision`, `created_at`, `updated_at`. `paused_by` can also be `pomodoro`: a break ended and the next focus waits for the student.

### `session_segments`
A learner table: the stretches a session is made of. `id`, `learner_id`, `session_id`, `kind` (`study` or `break`), `started_at`, `ended_at` (null while open; at most one open per session), `ended_by` (`pause`, `break`, `resume`, `end`, `away`, `long_break`, `log`, `pomodoro` or `skip`). A gap between segments is a pause.

### `flashcards`
A learner table (docs/specs/study-memory.md §4.5): `id`, `learner_id`, `workspace_id`, `topic_id` (nullable), `module_id` (nullable: the topic's module when the topic has one, else the module chosen for it, else the module of the session it was made in; kept when its topic moves, emptied when the module is deleted), `front` (≤ 500), `back` (≤ 1000), `revision` (its journal task's content revision), `author` (`student` or `ai`), `session_id` (the session it came from, if any), `step` (its rung on the ladder of gaps, 0 for a new or missed card), `due_on` (the student's date it's next due; null for a new card, due now), `reviews`, `lapses`, `last_result` (`correct`, `partial` or `incorrect`), `last_reviewed_at`, `retired_at`, `created_at`, `updated_at`. Each card is also a `task` record (`card-{id}`) in the journal, and each answer an `attempt`.

### `engine_settings`
A learner table (docs/specs/study-memory.md §6): a student's choices for the built-in chat's engine. `id`, `learner_id` (unique), `tutor_model`, `quick_model` and `fallback_model` (ids as the service names them, ≤ 120, nullable until chosen; the owner's defaults apply meanwhile), `session_cap_micros` and `month_cap_micros` (the most a session's chat and a month's chats may cost, in millionths of a dollar; 0 for no limit), `no_training` (keep the student's words out of model training; on), `language` (the language the tutor teaches in, up to 40 characters; null for the one the student writes in; 2026-10-05), `ask_topics` (whether the tutor asks before adding or switching topics; off, so it keeps them itself; 2026-10-06), `consented_at` (when the student agreed to the chat sending their study material to the provider; null withdraws it), `key_encrypted` (the student's own key for the service, encrypted with the app key and shown again only by its last four characters; null to use the key an admin set up for everyone; 2026-10-05) and `key_updated_at`, `created_at`, `updated_at`.

### `engine_threads`
A learner table: the built-in chat of one study session. `id`, `learner_id`, `workspace_id`, `session_id` (unique per learner), `note_id` (the session's study note the tutor writes in, once it has started one; nullable), `model` (the tutor model last used), `summary` (the oldest turns folded into a few lines by the quick model, nullable) and `folded_through` (the message position the summary stands for; messages up to it are kept but not sent), `spent_micros` (what the chat has cost, the folds and the wrap-up included), `turns`, `wrapped_at` (when the session's summary and checkpoint were written from the chat at its end, nullable), `created_at`, `updated_at`. The chat is a study aid kept to be read again and saved from (the write-back); the memory stays the journal.

### `engine_messages`
A learner table: the turns of a thread, in order. `id`, `learner_id`, `thread_id`, `position` (unique per thread), `role` (`user`, `assistant` or `tool`), `content` (the words; a tool's result for `tool`), `attachments` (JSON, the notes and files the student attached to a `user` message: each one's reference, name, kind, and the text that went with it to the engine, nullable), `tool_calls` (JSON, the look-ups an assistant turn asked for, in the OpenAI shape, nullable), `reasoning` (JSON, the model's reasoning as the service handed it back, sent back unchanged with the message to the same model, since some refuse a look-up's result without it; nullable), `tool_call_id` and `tool_name` (for a `tool` row), `effects` (JSON, for a `tool` row: what it did in the course, how many flashcards, key points, questions and topics it saved, the notes it wrote in with their versions, and the topic it gave the session; nullable), `model` (the one that answered, nullable), `tokens_in`, `tokens_out`, `cost_micros` (what the service said the call cost), `created_at`. The student's name and email are never in a message ViStud writes.

Note blocks arrive with the next M2 steps ([docs/specs/workspaces.md §8](../specs/workspaces.md#8-for-developers), [ADR 0003 §9](../adr/0003-web-workspaces-and-study-content.md#9-study-content-model)). Encryption columns and the key database arrive before another real learner joins (ADR 0001).
