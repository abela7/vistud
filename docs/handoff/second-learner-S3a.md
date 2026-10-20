# S3a: private upload and preview bytes

Delivered for review on 2026-10-21. This is the first half of the owner's S3
split, with raw OpenSSL GCM and a separate HKDF files key. No deploy, migration,
backfill or maintenance command was run against Abel's database or files.
Nothing has been pushed. S3b has not started; the learner gate remains closed.

## Delivered

- Versioned chunked binary format, strict learner/object/kind binding, encrypted
  length and digest, independently authenticated chunks and atomic verified
  publication. [The format specification](../architecture/private-file-format.md)
  fixes offsets, AAD, key derivation and the coverage of partial reads.
- Upload service shared by API, Files, CourseSetup and TopicPage; downloads and
  range streaming; encrypted note images, PDF previews and modern Office copies.
  Upload fingerprints use a keyed HMAC, not a readable plaintext SHA-256.
- Separate disposable private `derived` disk. Preview deletion/retry also clears
  old locations; diagnostics never read old tool-output failure markers.
- Note exports stream from a private temporary workspace. Office inputs,
  outputs, profiles and child-process temp directories stay inside that workspace.
  Ask, GuideChat, NoteAiController, upload controllers and Livewire use private
  upload temps. Consumed uploads are deleted on success/failure. Guide's model
  capability refusal happens before consumption, preserving its existing retry
  behavior; unused uploads expire through cleanup.
- Immediate workspace cleanup plus an hourly leased-workspace/expired-upload
  janitor. Logs use fixed failure codes instead of document text/tool output.
- Existing file readers receive decrypted temporary input so S3a does not break
  extraction. Extracted-text JSON and recording/transcription storage are still
  S3b work, with no provenance, versioning or extraction behavior change here.

No schema migration is needed for S3a. `files.size` remains plaintext byte count
for existing quota/UI semantics; only the **file header** hides the length.
Metadata names remain protected by S2. The two garbled-character leftovers in
Rollups.php and topic-page.blade.php and the two pre-existing handoff files are
excluded from this work.

## Required S3b backfill entries (not executable in S3a)

`PrivateFiles::S3A_BACKFILL` lists the runtime kinds/trees. S3b must add the
recording and extraction kinds, then implement one offline, bounded-memory,
dry-run/resumable/verified converter with a verified way back. Do not reopen a
mixed legacy/encrypted installation: runtime deliberately refuses plaintext.

| Objects | Inventory and verification requirement |
|---|---|
| `learners/<learner>/files/<file id>` on `vistud.files.disk` | All live **and trashed** uploads, matched to their owning row. Authenticate encrypted objects; stream-convert legacy bytes; verify full plaintext digest and byte count against the source before publication. |
| `learners/<learner>/note-images/<id>.<ext>` on source disk | Scan the tree as well as note references: there is no image table. Resolve learner from the trusted learner inventory, never an unauthenticated header. |
| `learners/<learner>/previews/<file id>.pdf/docx/pptx/xlsx` | Include old previews on the source disk and new ones on `vistud.files.derived_disk`. Verify/move/rebuild without leaving a readable copy; both source and derived locations need accounting. |
| `*.failed` under preview trees | Legacy files can hold tool output. Count and remove/recreate fixed codes without reading, logging or exporting their bodies. Include modern Office failure markers as well as PDF ones. |
| `<target>.writing-<random>` | Reconcile interrupted encrypted stages while writers are stopped; never treat one as a committed object or blindly discard a recoverable source. |
| `files.sha256` and `file_digests.content_hash` | Convert old fingerprints consistently from the same fully verified source to the files-key HMAC. Preserve digest reuse; do not leave the old unkeyed hashes or strand existing digest associations. |
| Old durable processing/temp locations | Inventory legacy preview/export/parser remnants and old Livewire temp storage. Do not include plaintext temps in backups or silently leave old copies behind. |

The future dry run must report counts/bytes/kinds, legacy/encrypted/verified,
missing/orphan/conflicting objects, unresolved owners/keys, stages and blockers,
without modifying files/DB or printing content. Resume by verified object
identity/generation, not by filename existence alone. Keep matching private
content/key/file backups and a conversion ledger; verify rollback on synthetic
data before offering it for Abel's release. S4 owns approved retention and the
calendar date for removing pre-encryption backups.

## Deployment dependency

S3a is reviewable code, **not a complete deployable file conversion**. Existing
uploads cannot be read with this code until S3b's offline conversion has run.
Abel runs that later with matching backups; this builder has not touched them.
See [deploy.md](../development/deploy.md#private-files-s3a-preparation-only) and
[operations.md](../development/operations.md#private-file-storage-and-temporary-bytes-s3a).
The Linux server must use RAM-backed private processing/upload directories,
including PHP and nginx request spooling; normal temp is accepted on this
Windows machine. No claim of secure disk erasure is made by unlinking a file.

## Validation

The full suite ran against temporary commit-tree snapshot
`a08725238598d655d8079a2a98077f7f92ecccb4`, parent `8a73df4`, in a clean
detached checkout. The final commit differs from that snapshot only in this
validation report. Composer's classmap and PSR-4 mappings were remapped to the
scratch checkout; reflection checked LearnerTables, Rollups, TestCase and
UserFactory before running. A vendor junction alone is insufficient isolation.

- Full suite: **1,521 tests; 1,519 passed; 12,012 assertions; 264.178 seconds**.
  Only the acknowledged AssignmentsTest error at line 88 (`Not found`, deleting
  an assignment removes its folder/files) and the Windows pcntl skip remain.
  FenceTest passed in this run. No other failure occurred.
- Focused final format/storage/Guide run before the snapshot: **27 tests,
  392 assertions, all passed**. An earlier broader 429-test run caught Guide
  discarding an attachment on a model capability refusal; the implementation
  was fixed and the existing test was kept unchanged.
- Pint passes on all owned PHP files. PHPStan passes on the other owned PHP
  files; NotesScreenTest has one pre-existing `method.alreadyNarrowedType`
  warning at line 110. An unchanged HEAD copy reproduces that exact warning.
  No suppression or baseline entry was added.
- Only named synthetic MySQL/key test databases on port 3307 and scratch test
  files were used. No local deploy/backfill was run. The full run was serial.

Tests include binary boundary/empty values, wrong identity/key, corruption,
reordering/duplication/mixed generations, truncation/appending, fresh nonces,
bounded-memory streaming, HTTP ranges, ownership/trash/restore, encrypted images,
exports, cleanup, failure preservation and diagnostics. The 32 MiB memory test
is a development check, not an S9 server-scale measurement.
