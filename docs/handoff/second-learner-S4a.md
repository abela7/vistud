# S4a: account erasure

Implementation only; no migration, deletion, conversion or deployment was run
against Abel's installed database or private files. Never push this phase.
S4b (independent ledger export, restore replay and approved backup policy) is
still required. Admission remains closed.

## Entry and timing

`Accounts::requestDeletion` blocks the account, ends sessions, revokes MCP access,
and inserts a durable request in the account transaction. Its after-commit job
is explicitly on the database connection, even when ordinary work is sync.
The minimum delay is 10,800 seconds (three hours); configuration can lengthen it.
A duplicate request does not create another erasure. A scheduler scan every ten
minutes recovers due unfinished requests, including dispatch failures. It also
discovers accounts marked deleted before S4 and gives each a full new three-hour
grace before erasure; it does not silently leave their old content behind.

Settings > Your data > Delete account posts through authentication, recent
password confirmation and a required checkbox. The account is signed out and
shown that deletion is pending. Downloaded copies and material already sent to
providers are outside ViStud; offline devices need their saved drafts cleared.
The existing last-active-admin safeguard applies.

## Inventory and order

1. Revoke/delete sessions, OAuth access/refresh/auth codes and user-owned clients;
   retain shared clients. Remove matching queued and failed payloads. Shared batch
   records survive with erased UUID references replaced by equal-length null UUIDs,
   without unserializing callbacks. If matching options contain serialized objects
   (including signed callbacks), leave them intact and postpone erasure before
   content/key deletion. The operator must complete/rebuild those shared callbacks
   without the erased learner, then retry; never edit signatures or discard other
   learners' callbacks. ViStud itself currently creates no such batches.
   Clear learner-prefixed topic/review/file-list/
   trouble caches, personal provider catalogue, MCP limiter and private-file locks.
   Validate all canonical storage keys are under the learner's private prefix.
2. Delete every `LearnerTables::TABLES` row using a system-only, privileged raw
   learner filter, children before parents. This includes journal, projections,
   outbox, MCP calls/connections/confirmations, encrypted profiles and settings,
   engine jobs, study content and all class tables. Remove resets, invitations,
   roles and identifying account fields; null shared invitation/settings/role
   actor references. Anonymise audit events through ADR 0004's exception.
3. Delete both configured local disks' `learners/<uuid>` trees. These cover uploads,
   modern Office copies, previews, note images, recordings, five-second pieces,
   extracted text, transcription diagnostics, conversion receipts and interrupted
   encrypted stages. Resolve containment before recursive removal and reject
   symbolic links. Verify learner content is absent. Prune expired processing
   workspaces using their existing age/lease rules and expired Livewire uploads;
   no owner is inferred. An expired workspace still leased by live work postpones
   completion rather than destroying a key while plaintext cleanup is pending.
4. Delete the learner's row from the separate key database and verify absence;
   forget the in-process key ring. Key destruction follows successful cleanup.
5. Sweep content, files, caches, queues, tokens and audit identifiers again without
   decrypting anything, then delete learner/account metadata. This catches late
   metadata. Revocation evicts the current scoped key ring; first key load and job/provider
   boundaries check current account status. Already-running requests/jobs finish
   within the bounded grace, and their rings cannot survive request/job boundaries. No per-learner barrier is introduced.
6. Transactionally append the content-free completion record and mark the request
   complete. The record contains request/user/learner UUIDs, request/completion
   timestamps and version only. No names, addresses, paths, errors or content.

Each stage is idempotent. A failure leaves an unfinished request, fixed error code
`erasure_retry_required` (or `shared_batch_cleanup_pending` for the callback
guard), and no completion row. Exceptions deliberately omit
private original exception text. Retries also work after key destruction or
account removal. Stages emit only request UUID and stage for testing/monitoring.
The runtime cannot UPDATE erasure requests or write the completion ledger; the
worker uses the configured owner connection. It never uses MySQL root.

## Release requirements for Abel (not executed here)

- Complete S4b before enabling real erasure or restoring backups. Preserve the
  independent ledger across every restore. The current ledger is not independent.
  Preserve pending erasure intent as well as completions: a crash between key
  destruction and completion logging must not let an older key backup resurrect
  the learner on restore. S4b must close that recovery window.
- Apply the new main-database migration as `mysql_owner`; the normal migration
  listener applies runtime grants. No key-schema migration or reprovisioning.
- Keep the database worker and minute scheduler running. Monitor unfinished rows
  by stage/error code and retry via `vistud:erasure:retry-pending` after fixing the
  cause. Worker timeout is 360 seconds; queue retry_after must remain above that
  (420 seconds today). A lock also serialises manual retries for one request.
- Enforce server FPM `request_terminate_timeout = 1800s` **including after-response
  work** (`request_terminate_timeout_track_finished = yes`). All execution paths,
  including CLI scripts that may write student data, must have a wall-clock limit
  below three hours. Transcription no longer disables PHP's timer; engine runs
  check active status and a 1,800-second deadline between provider calls.
  PHP's execution timer alone does not bound blocked I/O on Linux. Supervisor
  must kill stuck workers and their children before the erasure grace expires.
- Keep the existing hourly temp janitor. Production processing, PHP upload and
  nginx request spooling must be memory-backed as S3 documents; Windows uses the
  accepted normal temporary folder. No temporary owner registry is added.
- Finish and verify the S3 conversion first, including removal of reviewed legacy
  disposable processing folders. Non-private canonical keys block erasure before
  deleting rows, so paths cannot be lost and unrelated files cannot be guessed.
- Do not roll back nonempty erasure tables. Preserve their ledger and requests
  outside the rolled-back database first; S4b supplies the independent procedure.

Backup retention, offline master-key recovery and the dated plaintext-backup
retirement policy belong to S4b, using Abel's already approved numbers.

## Validation

Final full clean-copy run: **1,542 tests; 1,540 passed;
12,288 assertions**. Only the acknowledged AssignmentsTest error
at line 88 (`Not found`, assignment deletion removes its folder/files) and the
existing Windows pcntl skip remain. No new failure or skip remains in this run.

The tested commit-tree snapshot is `c35a48f78309ed0a598fe1cac9c7016b24bf8a76`,
parent `969240b`, in a detached checkout. Composer classmaps and PSR-4 paths are
remapped; reflection verifies LearnerTables, Rollups, TestCase and UserFactory
load from that checkout. Its working tree is clean. The final commit differs
only in this validation report. All 31 owned paths are included; the four
pre-existing leftovers are unchanged and excluded.

Pint and PHPStan pass for every owned PHP file, without new suppressions.
Focused runs passed 43 tests/442 assertions, then two final privilege/legacy
checks (18 assertions), the shared-callback regression, and 21 key lifecycle,
revocation and unchanged Rollups query-count checks (76 assertions).

Validation found and fixed a real regression: checking account status on every
decryption made the Rollups query count grow from 72 to 558 with course size.
The final implementation checks once when a scoped ring loads a learner's key,
evicts the current ring on revocation, and retains fresh job/provider checks.
Existing query-count assertions and Rollups source were not changed.

Earlier full attempts were stopped for the callback guard or aborted by PHP
request timers inherited across simulated requests (300 and 230 seconds).
Tests/TestCase now resets that timer between tests; production handler/job limits
remain finite. Those partial runs are not counted as full validation passes.
An intermediate OfficeReader fixture hit Windows ZipArchive's temporary-file
rename `Permission denied`; the unchanged class passed on rerun (9 tests,
32 assertions) and in the final full run. No Office reader or fixture assertion
was changed. This run spans an interruption; no server-performance timing is
claimed from it.

All database checks used only the named synthetic content/key schemas on port
3307. No installed learner database or private files were migrated, erased or
converted. No deployment or push was performed.

Builder regressions are not the independent G3 erasure/restore acceptance drill.
S4b still supplies independent ledger export/replay and the backup policy.
S9 must include bulk-update cost (S2), first-load/job account checks, and its
existing heavy-learner erasure measurement. Repeated decryptions reuse the scoped
ring; they must not add per-value account-status queries.
