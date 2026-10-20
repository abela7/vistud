# S1: learner keys

Delivered for review. Abel prepared the separate databases and grants. S1's tests
pass; the full clean suite has only failures reproduced on unchanged HEAD.
The second-learner gate remains closed. No later phase has started.

## What changed

- `LearnerScope::key(content|search|cache)` lazily uses a `scoped()` LearnerKeys
  ring, indexed only by learner ID. `encrypter()` returns Laravel AES-256-GCM
  with the content subkey. No key is loaded merely by constructing a scope.
- Explicit provisioning uses a unique learner primary key, a non-overwriting
  insert and authenticated read-back. `unlock()` never creates a missing key.
- The wrapped payload includes learner ID, data-key version and wrapping-master
  version; all are checked after decrypting. Purpose keys use distinct HKDF labels.
- `AccountFactory` provisions before the main transaction. `Accounts` and
  `Invitations` run their audit/invitation updates through its transaction callback.
  A confirmed rollback removes the key. An ambiguous database failure retains a
  possible orphan instead of risking deletion of a committed learner's key.
- `learners.key_provisioned_at` prevents the existing-learner command from
  silently replacing a missing/destroyed key. Provisioning has a dry run, reports
  orphans, and explicitly prunes orphan rows older than one hour while offline.
- Rotation uses active/previous environment masters and commits each row
  atomically. Mixed versions remain readable; a rerun verifies/skips completed rows.
- Laravel's normal request/worker scoping handles lifetime. Additional queue
  start/attempted cleanup covers sync and after-response jobs, including exceptions.
- ExtractFileText and MakeFilePreview carry learner IDs and recheck file ownership.
  The upload preview's existing `prepare(file, storageKey)` obtains the ID from the
  trusted canonical storage path; it still rechecks the scoped database row when
  executing. This leaves the pre-existing dirty `app/Study/Files.php` untouched.
  Already-queued pre-S1 file jobs use the same path/ownership check during upgrade.
- Key tables are registered separately in LearnerTables and architecture checks;
  key migrations never enter the main migration path. Runtime DDL is not granted.
- The old source-text gate heuristic is removed. It cannot open admission during
  S1; executable checks remain S7/S8 work.
- Browser CI prepares the separate key schema and generates a synthetic master
  into its ephemeral process environment before account fixtures run. No fixed
  test key or real secret is added to the workflow.

No personal content/file encryption or erasure is implemented in S1. No root
connection, live migration, live provisioning/backfill, or push was performed.
The independent G2/G3 acceptance suites remain the validator's work.

## Validation

- 7 tests passed / 23 assertions: MasterKeysTest, SecondLearnerGateTest,
  StudentWordsTest. All secrets in the new tests are generated synthetically.
- PHPStan passed for changed application code and new tests (no suppressions).
- Pint applied to this phase's PHP files only.
- Initial database validation stopped on MySQL error 1044. Abel then confirmed
  both databases/grants were prepared. No root connection was used by this agent.
- All 19 key-lifecycle feature tests passed (63 assertions).
- Another 103 tests passed (673 assertions): Identity, FileTextsTest,
  FilesScreenTest, Mcp/PrincipalTest and the Architecture suite.
- The key lifecycle tests cover insert/read-back, per-learner and per-purpose
  isolation, missing master/key, payload substitution/version/ciphertext failures,
  interrupted rotation, account rollback, repeatable provisioning, orphan cleanup,
  web/Livewire, and A → B → A through sync and a real database worker with B failing.
  After-response cleanup is also covered. These database checks now pass.
- Full suite on isolated snapshot `6920fbd09bd863aa9818b6ea3475600eb20560e7`:
  **1,464 tests; 1,446 passed; 5 failures; 12 errors; 1 skipped; 10,825 assertions**
  (233.8 seconds). No S1 test failed or was skipped.
- All **17 failures/errors** were rerun on unchanged HEAD `665c18c` in a second
  clean worktree: **28 selected cases; 11 passed; the identical 5 failures and
  12 errors** (16.9 seconds). Thus these are baseline issues, including more than
  the four mentioned in the brief. No unrelated dirty file was edited to fix them.
- The skip is the existing process-forking ConcurrentAppendTest, which requires
  Unix's pcntl extension. S1's database-worker test runs on Windows and passed.
- Validation uses a private Git index containing only S1 files and dependency
  junctions. The scratch bootstrap normalizes Composer's optimized classmap paths
  with `realpath()` before redirecting application/test/factory autoloading;
  reflection confirmed application **and** test classes load from the clean tree.
  An initial run was stopped when this Windows junction issue was found; the
  full result above is from the corrected harness.
- No JavaScript/CSS changed. Browser tests themselves were not run in this phase.

### Existing failures reproduced before S1

| Cases | Existing problem |
|---|---|
| StackTest: session without topic/module (1 failure) | Standing context is 11,131 characters, above 11,000 |
| ToolboxTest: provider enums (1 failure) | `log_progress.properties.kind` is a map, not a list |
| Mcp/ReadToolsTest: narrow by module (1 failure) | Tool returns an error instead of success |
| Mcp/UndoConnectionTest: answer undo (1 failure) | Expected recorded acknowledgement is null |
| AdapterParityTest (1 failure) | Registry names absent route `api.v1.me.update` |
| FenceTest, ToolboxTest, BriefingsTest, WriteBackTest, SessionAllInOnePanesTest, SessionChatFirstTest (8 errors) | `QuestionDetails::open()` is missing in committed HEAD |
| FenceTest and ToolboxTest (3 errors) | `PlanProgress::words()` is missing in committed HEAD |
| AssignmentsTest: folder follows assignment (1 error) | Folder returns Not found |

Internal interface/schema additions are described here and in schema.md;
`docs/architecture/contracts.md` already has someone else's edits and was not changed.

## Database preparation (completed by Abel)

Initially `vistud_owner` could administer only `vistud` and `vistud_test`. Abel
confirmed the following preparation on local MySQL 8.4 (port 3307), after which
the test key database worked. The agent never connected as root. These statements
reuse the existing local users and set no passwords.

```sql
CREATE DATABASE IF NOT EXISTS vistud_keys CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS vistud_keys_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON vistud_keys.* TO 'vistud_owner'@'%' WITH GRANT OPTION;
GRANT ALL PRIVILEGES ON vistud_keys_test.* TO 'vistud_owner'@'%' WITH GRANT OPTION;
GRANT SELECT, INSERT, UPDATE, DELETE ON vistud_keys.* TO 'vistud_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON vistud_keys_test.* TO 'vistud_app'@'%';
```

Production uses its actual database users instead of these local user names.
Do not migrate or provision the real databases until Abel has approved it and taken a backup.

After the approved migration, provision existing learners (including Abel) before
reopening the app. The deployment guide will give the complete order.

## Deploy and recovery

[deploy.md](../development/deploy.md) gives the exact sequence: maintenance,
stopped workers/scheduler, key migration, main migration, provisioning dry run,
provisioning (including Abel), verification, then restart/reopen. Do not run those
against the live installation without Abel's go and a backup.

`VISTUD_MASTER_KEY` and optional `VISTUD_PREVIOUS_MASTER_KEY` contain `base64:` plus
32 random bytes encoded in base64. Production must inject them directly into every
PHP/worker/scheduler process; they are deliberately absent from cached config.
Rotation takes no CLI secret arguments and prints no secrets.

Losing the master or key database without an independent recovery copy means
losing all encrypted student content. Operations.md proposes separate 30-day data
and 7-day key-backup retention (Abel decides), an offline encrypted master copy,
scratch recovery verification and a monthly restore drill. S4 must implement the
erasure ledger and agree an actual deletion date for pre-encryption plaintext backups.
