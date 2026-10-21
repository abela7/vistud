# S7: the privacy policy and the real gate

Built by Grok (stopped by a usage limit) and finished and checked by Claude. Not pushed.

## What changed

- `docs/legal/privacy.md`: a plain-English draft. What ViStud keeps and where, how long, who processes it, class
  recordings and the people in the room, export and deletion. It starts "DRAFT: needs a human legal review."
  **Abel must have it reviewed by a person before real students.** Shown at `/privacy`; each sign-up stores the
  version (a hash of the file) the student accepted.
- `php artisan vistud:gate:second-learner` (`App\Identity\AdmissionGate`) now checks the real installation, and
  only reads. It prints each check, then `result: open` or `result: closed`, and fails (exit 1) unless open:
  1. the master key loads, and a learner key wraps and unwraps in memory (nothing stored);
  2. the key database is separate from the content database;
  3. learner text is encrypted: every row of every encrypted table has the current `content_version`;
  4. files are encrypted: every stored student file, on the files disk and the derived disk, starts with the
     encrypted-file marker, and no half-written stage is left;
  5. a student without a key of their own gets no installation key (`Settings::credential()`, in memory);
  6. an acceptance run is recorded for this exact commit and passed;
  7. the policy has no `DRAFT` line and has a `Reviewed by: <name>, <YYYY-MM-DD>` line.
- `php artisan vistud:gate:record-acceptance` runs `tests/Acceptance/G2` and `G3` in a child process on
  `vistud_test` and `vistud_keys_test` only, then writes the commit and the result to
  `storage/app/gate/acceptance.json` (git-ignored). Any change after that commit makes the record stale.

## Changes made after Grok

- The files check called `vistud:encrypt:files --dry-run` from inside the gate. That command refuses to run
  unless the site is in maintenance, and calling a command from a command left the gate printing nothing.
  The gate now reads the first bytes of each file itself.
- Two redundant checks removed (static analysis).

## How the gate is opened

Today it answers `closed`. On Abel's local installation it fails checks 3 and 4 (the data is not converted yet),
6 (no recorded run) and 7 (the policy is a draft). To open it:

1. Convert the data (deploy.md, "Learner keys" and the S2/S3 backfill sections).
2. `php artisan vistud:gate:record-acceptance` on the commit being released.
3. A person reviews the policy; Abel adds `Reviewed by: <name>, <date>` and removes the DRAFT line.
4. `php artisan vistud:gate:second-learner` answers `open`.

The gate is a check, not a lock: closing it does not stop `/join` by itself. Do not hand out class links until
it answers `open`.

## Tests

`tests/Feature/Mcp/SecondLearnerGateTest.php`: each check has a fixture that fails it (missing master key, key
database named like the content database, a row with an old content version, a plain file and a half-written
stage, an admin principal, a missing/stale/failed acceptance record, a draft or unreviewed policy), the gate
opens only when all pass, and the acceptance run always targets the test databases.

## The first local setup (2026-10-21, Abel's machine)

Done by Claude with Abel's go, after a verified backup. What it found, for the real deployment:

- The local database already held the S1-S8 schema and test accounts with keys, from builder runs against it. The
  provisioning dry run is the place to see that: it lists who would get a key.
- 1,630 old test accounts (`@example.test`) owned 12,915 stored files of accounts that no longer exist, plus
  disposable work folders. The converter blocks on unmapped files by design. They were **moved, not deleted**, to the
  backup folder; three throwaway test uploads whose bytes no longer matched their record were deleted with the app's
  own permanent delete. On the real server, run the dry run first and expect to reconcile the same way.
- The conversion left `learners/<id>/file-conversion/*.state` receipts (sealed, no file marker). The gate now skips
  that folder, as the converter does.
- Order that worked: backup, `down`, both migrations, `vistud:keys:provision`, `vistud:encrypt:backfill` (dry run,
  `--backup-confirmed`, `--verify`), `vistud:encrypt:clear-text-caches`, `vistud:encrypt:files` (dry run,
  `--backup-confirmed`, `--verify`), `vistud:encrypt:ai-keys`, `up`.
