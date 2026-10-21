# S3b: recordings, transcription, extraction and offline file conversion

Built after S3a approval. No deploy, migration, conversion or maintenance
command has been run against Abel's database or student files. No push.
S4 and the independent G2/G3 validation remain later work; the gate stays closed.

## Runtime

Recording pieces are sealed when accepted, with their index in the authenticated
kind. Duplicate indices keep the first piece. Assembly decrypts pieces in order
through a generator and reframes them into one encrypted file, without a
plaintext joined copy or a whole-file buffer. Pieces are removed after metadata
commit. Recording bytes/quota remain plaintext lengths; sha256 now holds the
same files-key HMAC fingerprint as uploads. Playback uses authenticated ranges.

TranscribeClass and TranscribeTry decrypt only into leased processing workspaces;
ffmpeg's input, output and TMP/TEMP/TMPDIR stay there. Cleanup runs on success
and failure. Tool exceptions no longer expose stdout/stderr or source paths.
TranscribeTry still prints notes/costs and saves both outputs, now encrypted
under the learner's `transcribe-try/<class>/<run>` tree. Its study-note behavior
is unchanged. Its saved files can be decoded through PrivateFiles with that
learner's scope, or read as ordinary Markdown after an offline rollback.

Extracted-text JSON is encrypted with the existing v3 schema, page limits,
cache behavior and parser caps. No extraction version/provenance changes.

HTTP responses prefetch and authenticate only the first requested chunk, retain
it, and decrypt each subsequent chunk once. A bad later chunk throws before any
of its bytes are yielded; the successful prefix can already have been sent and
the stream ends short of Content-Length. The new test checks that exact case.
See [the format](../architecture/private-file-format.md).

## Offline command

`vistud:encrypt:files` covers S3a and S3b: live/trashed uploads, note images,
PDF/modern Office previews on old/new disks, extracted JSON, recording pieces
and joined files, saved TranscribeTry output, old failure markers, interrupted
stages and dedicated old processing/upload temp folders. Traversal and payload
conversion are bounded-memory; existing capped JSON/parser/AI consumers retain
their old memory behavior. No new database migration is needed.
Image references in notes and their historical versions are checked too, so a
referenced image missing from the disk blocks. Receipts are capped at 8 KiB
before they are read/decoded; larger or corrupt metadata fails closed.

- `--dry-run`: reads/verifies and reports counts/bytes only. Creates no locks,
  receipts, directories or replacements. Counts objects, bytes, legacy/encrypted,
  verified/changed, missing/orphan/conflicting objects, stages, markers, temps
  and blockers. Keys and trusted owners must resolve. Unknown learner filters
  fail. No student text, raw hashes, paths or tool error bodies are printed.
- Default conversion: requires maintenance and `--backup-confirmed` outside
  tests; workers, scheduler and upload writers must also be stopped. A local
  flock prevents two conversion commands. Offline command locking does not stop
  old workers by itself. Every replacement is synced, reopened and fully
  authenticated/digest-checked against its source before atomic publication.
  Source copies on old preview disks are removed only after verified target,
  receipt and metadata updates. Conflicting copies block and survive.
- Resume: repeat the same command. Authenticated per-object pending/completed
  receipts and read-back verification handle crashes before/after publication
  or metadata updates. Already converted payloads stay byte-for-byte unchanged.
  Current file/recording hashes and file_digests.content_hash are checked and
  converted together; unrelated historical digest hashes block for review.
  Missing keys never trigger provisioning or plaintext fallback.
- `--verify`: read-only; requires all objects/fingerprints in encrypted canonical
  locations, no missing sources or remaining legacy processing data. It fully
  authenticates every encrypted object. Dry run/verify require maintenance in
  real environments too. A selected learner checks only that learner; use an
  unrestricted verification before reopening.
- `--limit=N`: intentionally returns failure with a partial-inventory message
  if it stops early. Repeat unrestricted to finish; it is never a release pass.
- `--decrypt --backup-confirmed`: verified offline rollback, also resumable.
  Restores ordinary source bytes and original SHA fingerprints; previews move
  back to the source disk. It does not restore older application code, erase
  receipts or undo the separate S2 text conversion. TranscribeTry Markdown is
  preserved under its learner-owned path, including imported old output.

Interrupted stages are only removed when the canonical object verifies against
its identity and applicable row metadata. Complete conflicting authenticated
stages, damaged sealed stages and stages with no canonical copy block for
operator recovery. Partial unsealed stages with a verified canonical copy can
be discarded offline. No unchecked target deletion is used for Windows rename.

Legacy TranscribeTry transcript/notes are imported and sealed under the owning
class/learner, verified, then removed from the old processing folder. Unknown
saved Markdown blocks and is never purged as a temp. Remaining dedicated old
processing files require explicit `--purge-temp-confirmed` **after** preserving
any needed diagnostic artifacts in private backups. Purge is global, never
available with a learner filter; dry run/verify do not purge anything. This
flag only unlinks old disposable processing/upload files, not canonical data.

Authenticated receipts live under each learner's `file-conversion` directory
and must accompany canonical backups. They carry sealed identity/fingerprint,
length, direction and completion state. No readable unkeyed content hash is
added. Known corrupt encryption headers are refused rather than wrapped again.
Before an object has any receipt, the operator must compare the initial legacy
inventory with the verified backup: objects without a database fingerprint
(images/extracts) cannot universally distinguish arbitrary corrupt ciphertext
from arbitrary legacy bytes if their entire magic was destroyed. Runtime never
has this ambiguity: it only accepts authenticated ciphertext. Do not mark an
ambiguous source as legacy without recovering it from a known good backup.

## Release and recovery

Use [deploy.md](../development/deploy.md#private-files-s3b-offline-conversion)
and [operations.md](../development/operations.md#private-file-conversion-and-recovery-s3b).
Take matching database, canonical files, receipts, separate key database and
master recovery backups first. Keep the app offline until text conversion,
file conversion and unrestricted file verification all pass. Production temp
must already be RAM-backed, including PHP/nginx spooling. Normal Windows temp
is the explicitly accepted development setup. Pre-encryption backups stay
readable until S4's approved retention/calendar deletion procedure removes them.

## Validation

Pint and PHPStan pass for every owned PHP file, without new suppressions.
Focused validation: **39 tests, 380 assertions passed** across file conversion,
file extraction, TranscribeTry and architecture; a later focused run with the
final inventory checks passed **11 tests, 95 assertions**. The earlier broader
class run passed 55/56 before a new fixture's missing module was corrected.
The existing FileTextsTest Office fixture hit a Windows ZipArchive rename lock
once and passed on rerun. No implementation assertion was weakened: old raw
recording assertions now decode through PrivateFiles, and transcription fakes
also assert that ffmpeg receives the original decrypted recording bytes.

The final full suite uses commit-tree snapshot
`2d6a13318258ff47e8b0a41a09166d8b857d2aba`, parent `5a398ac`, in a clean
detached checkout. Composer classmaps and PSR-4 paths are remapped, with reflection
checking LearnerTables, Rollups, TestCase and UserFactory load from scratch.
The final commit differs from this snapshot only in this validation report.
One intermediate full run was stopped before completion to add the missing-image
and bounded-receipt checks; no partial run is counted as a validation pass.

All database tests used the named synthetic content/key schemas on port 3307.
No real learner database or files were converted, and no production/S9 scale
measurement is claimed.

Final full clean-copy run: **1,532 tests; 1,530 passed; 12,112 assertions;
292.402 seconds**. Only the acknowledged AssignmentsTest error at line 88
(`Not found`, assignment deletion removes its folder/files) and the existing
Windows pcntl skip remain. FileTextsTest and FenceTest passed in this full run.
No new failure or skip remains. The 20 owned files are committed; the four
pre-existing leftovers are unchanged. No deployment or push was performed.
