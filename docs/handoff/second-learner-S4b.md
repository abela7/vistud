# S4b: independent erasure ledger and restore replay

No migration, backup, erasure or restore was run against Abel's installed database
or private files. No real secret was generated, printed or committed. No push.

## Delivered

- Separately configured, content-free append-only journal outside the checkout
  and data restore sets. Chained HMAC records bind version, installation UUID,
  sequence, previous hash and strict UUID/time-only payload. A separately fsynced
  authenticated head detects committed truncation. Exclusive locks serialize
  writers and exports. Interrupted publication can recover only past an intact
  authenticated checkpoint, never discard an acknowledged record.
- Independent intent before deletion acknowledgement, and before erasure work;
  completion after cleanup/key destruction and before local completion commit.
  A lost DB commit reuses authoritative request UUID/timing, and the ten-minute
  scan imports missing local intent. No plaintext, learner key, APP_KEY or master
  is put in this ledger. Its independent auth key comes directly from process
  environment; nothing puts it in config cache.
- `vistud:erasure:ledger init|status|recover|sync|export`, with read-only `--dry-run`.
  Init is explicit, offline and create-only. Sync imports approved S4a history;
  export is immutable/create-only and prints only count/head. Export/status/recover work without MySQL or the
  backup master; only explicit bootstrap sync imports DB history. Provision the root
  yourself; there is no fallback to application storage or an old database.
- `vistud:erasure:replay FILE --head=HEAD --dry-run`, then the same with
  `--offline-confirmed` in maintenance with workers stopped. It validates the
  whole archive and latest independent intent set before DB writes. Old exports
  missing newer intent, corruption, wrong installation/key/head and identity
  mismatches fail closed. Completion-only advances permit retry of an interrupted
  replay. All accounts are blocked first; S4a performs actual cleanup, even when
  local completion flags survived a key-only/file-only restore. Original grace
  remains; deferred/incomplete replay exits nonzero and must not reopen traffic.
- Approved policy in operations.md: data/source files 35 days, keys 7, binlogs 7,
  operational logs 30, independent erasure evidence indefinitely, pre-encryption
  backups deleted by verified conversion + 14 days, two offline master recovery
  copies. It covers volume images/key-bearing replicas, calendar worksheet,
  private custody locations, key recovery, freshness and the monthly restore drill.

## Setup for Abel (not executed here)

Read [operations.md](../development/operations.md#independent-durable-ledger-setup-first-deployment-only).
Provision an independently retained/off-host filesystem root with verified locking,
fsync and atomic publication, configure its stable UUID and independent 32-byte
ledger authentication secret privately, then initialise once while offline, sync
S4a history, export and retain the checkpoint separately. Supply settings to all
FPM/CLI/worker/scheduler processes. Missing configuration deliberately blocks
accepting new erasure requests and running cleanup, rather than losing evidence.
No new schema migration or MySQL root access is needed for S4b.

Never initialise a replacement ledger during recovery. Fetch latest independent
storage/evidence before restoring data or keys. Store pending intent, not just
completion rows. A response/transaction failure does not cancel intent already
persisted independently. An export alone cannot prove it is latest: the separate
live checkpoint/control register must establish freshness. Copies on the same
restored volume are not independent.

The accepted +14-day plaintext-backup deadline needs an actual UTC conversion date
and a recorded calendar deletion date in Abel's private release record. Conversion
has not been run here; no fake date or physical recovery-kit location is invented.

## Validation

Clean committed snapshot `e384cd7`, detached checkout with Composer classmap and
PSR-4 remapped to that checkout (including junction escape assertions): **1,551
tests, 1,549 passed, 12,421 assertions** in 629 seconds. Only the known
`AssignmentsTest::test_its_folder_follows_its_name_and_its_module_and_stays_with_its_files_when_it_goes`
error at line 88 and the existing Windows skip remain. No new failure or Windows
ZipArchive flake occurred. Only the named synthetic `vistud_test` and
`vistud_keys_test` schemas and synthetic temporary files were used.

All nine new ledger/restore regressions passed: strict content-free records,
alteration/reordering/truncation and wrong-key/site refusal, authenticated tail
recovery, export without MySQL/master, latest-intent enforcement, interrupted and
key-only/file-only replay, lost DB-commit recovery, identity conflicts and an
unrelated learner preserved. Pint and PHPStan pass for all owned PHP files;
`git diff --check` passes. The only change after the tested snapshot is this
validation report.

Builder tests do not replace the independent G3 dump/restore/browser drill, a
real-server monthly drill or S9 measurements.
Admission remains closed until the later required phases pass.
