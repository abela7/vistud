# Running vistud.app

What to watch on production, and what to do when something goes wrong ([deploy.md](deploy.md); plan section 5).

## Checks

| What | How | Alert when |
|---|---|---|
| The app is up | `GET https://vistud.app/up` | not 200 |
| The MCP door answers | `POST https://vistud.app/mcp` with no token | not 401, or no `WWW-Authenticate` header |
| Sign-in discovery | `GET /.well-known/oauth-protected-resource/mcp` and `/.well-known/oauth-authorization-server` | not 200 |
| Token exchange is fast | the `/oauth/token` time in the nginx log | above 5 s (Claude gives up at 10 s) |
| The queue runs | `failed_jobs` count; the oldest row in `jobs` | any new failure; a job older than 10 min |
| The scheduler runs | `php artisan schedule:list`, and the newest `passport:purge` | not run for over 5 min |
| Disk and backups | disk use; last night's backup file | over 80 %; no backup |
| The certificate | days to expiry | under 14 days |

Error reports carry the request id and never a request body: tool arguments and answers are the student's material (docs/architecture/conventions.md).

## When something goes wrong

- **Stop every AI app at once:** set `MCP_ENABLED=false` in `.env`, `php artisan config:cache`. `/mcp` answers 503 and connections stay as they are. Undo by setting it back.
- **Cut one student's apps:** the student uses Settings › AI › Disconnect all. A suspension, a deletion request, a 2FA reset or a new password does it too.
- **A chat app keeps asking to sign in:** check that `APP_URL` is exactly `https://vistud.app`, that nothing redirects `/mcp`, and that `/mcp` is not behind a proxy that strips `Authorization`.
- **"Preparing" never ends for a file:** the queue worker is down (`supervisorctl status`), or the file is broken: a `texts/<id>.json.failed` mark beside it means there is no text.

## Learner keys (S1)

Losing the master key or the key database, without a usable independent backup,
means losing every student's encrypted content. `APP_KEY` cannot recover it.
Keep an offline encrypted copy of the master key under Abel's control, separate
from database and file backups. Record the physical location and recovery access
privately, not in this repository. Restore-test it in a scratch environment before
depending on it. Inject secrets directly into PHP-FPM/worker/scheduler environments;
never put them in config cache or a database dump.

The S4 backup policy below is approved by Abel: data/source-file backups 35 days,
separate key backups 7 days, binlogs 7 days, operational logs 30 days, independent
erasure ledger indefinitely, two offline master-key copies. The S4 procedure
supersedes the earlier S1 proposals. Pre-encryption backups are deleted no later
than 14 days after verified conversion, on the calendar date recorded below.

Recovery: take the app offline, stop workers, recover the master from the offline
copy into the process environment, restore the separate key database in scratch,
verify keys and a content round trip, and only then plan a live restore with Abel.
Reapply the latest independent S4 ledger before any access. Never restore an erased learner's
key just to make old content readable.

### Rotate the master without rotating learner data keys

1. Back up the key database privately. Generate a fresh master privately.
2. Supply the new secret as `VISTUD_MASTER_KEY`, and the old one as
   `VISTUD_PREVIOUS_MASTER_KEY`, to **all** readers and writers. Stop the app and
   workers while changing environments, then restart them consistently. Old-only
   processes must not run once rewrapping starts.
3. Run `php artisan vistud:keys:rotate`. Each learner row is authenticated and
   replaced atomically; reads accept both versions. If interrupted, run the same
   command again with the same pair. Already rewrapped rows are verified and skipped.
4. Verify every row with another successful run. Only then remove the previous
   secret from live processes and restart them all. Keep the old master offline
   only as long as retained key backups require it. Do not start another rotation
   while the first is incomplete.

Do not provide secrets as command arguments: shell history and process listings
can expose them. Rotation never prints keys.

### Existing learners and orphan keys

Provisioning is an explicit offline operation, never a side effect of reading.
`vistud:keys:provision --dry-run` reports what would happen; without `--dry-run`
it provisions only learners never marked as provisioned. A missing key for a
previously provisioned learner is an error, not a reason to generate a new one.
Failed account creation removes its precreated key. A crash can leave an orphan:
with the app down and workers stopped, `vistud:keys:provision --prune-orphans`
reports and removes keys with no learner after a one-hour grace period. Run the
dry run first. No live maintenance command has been run as part of S1 development.

## Core text migration and recovery (S2a)

Use the offline sequence in [deploy.md](deploy.md#core-personal-text-s2a-offline-conversion-before-reopening).
The app and workers must not serve mixed legacy/encrypted rows. Backfill reports
only counts; keep the dry-run and verification reports with the private release
record. Unowned/outstanding rows and missing/destroyed keys are blockers, never a
reason to make a replacement key. The schema owner converts append-only journal
storage; runtime journal privileges remain unchanged.

Clear the named ChooseTopics/Troubles learner caches during that release; they
refill encrypted. Do not convert them, flush unrelated caches, or leave an old
worker writing plaintext after the purge. Provider refusal logs retain status,
provider and model only: a provider's error body can echo personal text.

An S2a rollback requires maintenance, a verified backup and working learner keys:
verified `vistud:encrypt:backfill --decrypt --backup-confirmed`, schema rollback,
then the earlier application. Keep the app down throughout. A restore must pair
the content schema/encoding, separate key database and correct master environment.
Never test this against Abel's database as a development check. Pre-S2/S3 backups
are still readable; the approved S4 retention/calendar-deletion procedure below applies.

## Remaining text conversion (S2b)

S2b uses the same offline procedure and adds `--phase=S2b` to select just its
tables. The default command selects both phases. A dry run counts rows, non-null
content cells, legacy/encrypted row versions, learners and blocked rows without
writing data or caches. Each suggestion row also has one derived name hash;
verification checks it against the decrypted name. Converting, resuming and
verified decryption all include this hash. Never roll back S2a's schema while
leaving encrypted rows in it. See [deploy.md](deploy.md#core-personal-text-s2a-offline-conversion-before-reopening).

### S9 measurement follow-up from S2b

Measure bulk metadata updates on the real server or an equally sized copy,
including trashing/restoring a heavy module's notes and moving a large folder.
`ContentQuery::update` currently locks, decrypts and re-encrypts every matched row
even when only metadata changes. Record rows affected, rows/second, p50/p95/p99
latency, peak memory, lock duration and concurrent-request impact at S9's learner
sizes and concurrency levels. Add the measured results to ADR 0001 G4 before a
scale pass; a fast run on this Windows development machine is not a pass.

## Private file storage and temporary bytes (S3a)

Canonical uploads and note images remain on `vistud.files.disk`; encrypted
previews and modern Office copies use `vistud.files.derived_disk` (default
`derived`, `storage/app/derived`). Both disks are private local seekable
filesystems. Unsupported remote adapters fail closed. Keep encrypted sources,
note images and existing extracted text in matching data/file backups; derived
previews are rebuildable and excluded. Keys/master need the separate recovery
arrangements above. S3b still has to encrypt recordings and extracted text and
convert all old objects; S3a alone must not be deployed over legacy files.

Production plaintext processing must use RAM-backed private storage. Example
for a dedicated `www-data` PHP/nginx service account (adapt uid/gid and capacity
to the real server; these are configuration examples, not commands run here):

```fstab
tmpfs /run/vistud-tmp tmpfs rw,nosuid,nodev,noexec,mode=0700,uid=www-data,gid=www-data,size=1G 0 0
```

Create `php`, `nginx` and `processing` subdirectories there with mode 0700 and
the correct service owner. A separate nginx account needs its own restricted
subdirectory/mount. Supply this setting before config caching:

```dotenv
VISTUD_TEMP_ROOT=/run/vistud-tmp/processing
```

Set PHP `upload_tmp_dir=/run/vistud-tmp/php` and
`sys_temp_dir=/run/vistud-tmp/php` in **both** FPM and worker/CLI php.ini, plus
`TMPDIR`, `TMP` and `TEMP` for their service environments. Set nginx
`client_body_temp_path /run/vistud-tmp/nginx;` so request spooling is private RAM
too. PHP receives uploads before Laravel can handle them; setting only the
application temp root is insufficient. Verify the mounted filesystem, ownership
and actual PHP/nginx paths on the real server before reopening. Review swap,
hibernation, crash dumps and backups so RAM-backed plaintext is not persisted
through those channels. Size mounts against the real maximum upload, concurrent
uploads and Office conversions; 1 GiB is an example, not measured S9 capacity.
Out-of-space failures must fail the operation, never redirect to disk temp.

Livewire's `temporary` private local disk is under
`VISTUD_TEMP_ROOT/uploads`, outside durable source storage. Every processing
workspace has a random name and an exclusive lease. LibreOffice input/output,
profile, HOME and TMPDIR/TMP/TEMP are confined to that workspace. Parsers use a
decrypted workspace only when needed; range playback uses no plaintext file.
Exports stream their result and close the workspace afterward. Smalot PDF, GD
and base64 AI processing retain their existing in-memory caps. Recording/ffmpeg
temp routing is S3b, not claimed complete here.

Consumed upload temps and processing workspaces are removed in finally blocks;
model validation can retain an unused Guide attachment for retry. Normal cleanup
also runs on workspace destruction. The scheduler runs
`vistud:files:prune-temps` hourly: unlocked workspaces older than one hour and
Livewire uploads older than one hour are removed (upload time is capped at ten
minutes). Active workspace leases are preserved regardless of age. Interrupted
cleanup with a missing lease is retried after the same grace period. Windows
file locks can defer deletion; a fixed `temporary_cleanup_pending` log code
records this without contents/paths and the janitor retries. Monitor cleanup
failures and RAM use. A killed writer's **encrypted** sibling `.writing-*`
stage is left for S3b's offline reconciliation, not this temp janitor.

On this Windows development machine the normal system temp directory is
explicitly accepted, with a per-project private application subdirectory.
This is not the production RAM guarantee. Unlinking a file is not secure media
erasure; old temp directories and pre-S3 backups need the later offline inventory
and S4's approved calendar deletion plan. No real student files have been
converted, deleted or deployed as an S3a development check.

## Private file conversion and recovery (S3b)

S3b encrypts recording pieces on arrival, assembles encrypted joined recordings
without plaintext staging, encrypts extracted JSON and saves TranscribeTry output
under the learner's private tree. ffmpeg now receives leased temporary input;
its output and TMP/TEMP/TMPDIR are private processing paths. Use the production
RAM-backed configuration above for FPM, CLI, workers, scheduler and nginx.
The old S3a statement that ffmpeg/extracts remain future work is superseded here.

Run the offline [S3b release procedure](deploy.md#private-files-s3b-offline-conversion).
Maintain matching source files, learner `file-conversion` receipts, database,
separate keys and master recovery backups. Derived previews stay rebuildable.
Receipts are authenticated and encrypted, contain no readable unkeyed content
hash, and allow conversion/rollback to resume through publication/metadata gaps.
No key recreation or plaintext runtime fallback is permitted.

Dry-run and verification reports contain counts only; save them privately with
the release record. A limit returns a partial failure, and a learner filter is
only a selected inventory. Verify without either before reopening. Missing
sources/owners/keys, mismatched row fingerprints, unknown objects, corrupted
frames/receipts, conflicting copies and unresolved stages block. Refer to
[the handoff](../handoff/second-learner-S3b.md) for the first-inventory ambiguity
of destroyed magic on untracked legacy images/extracts: resolve against a good
backup instead of converting guessed legacy bytes.

Backfill imports old saved transcript/notes to encrypted learner-owned storage.
It inventories old source-disk previews-work, exports-work and livewire-tmp,
plus storage/app processing/transcription folders. Remaining disposable temp
files need explicit operator review and `--purge-temp-confirmed` (global only).
Unknown saved Markdown blocks and remains intact. Neither dry run nor verify
deletes it. Deleting a temp file is not secure media erasure; the approved S4
policy below still requires a recorded calendar date for removing readable old
backups.

Rollback decrypts/fully verifies files offline and restores source-disk preview
locations and original fingerprints; retain receipts and deploy S2b, keeping its
text conversion/schema. If interrupted, repeat with the same keys/backups and
direction. Never reopen a mixed store, destroy keys to fix a read, or overwrite
a conflicting recoverable source. No production restore or local conversion
was performed during S3b development.

## Log of drills (results)

| Date | Drill | Result |
|---|---|---|
| 2026-10-20 | S2a schema/data rollback and redeployment on Windows, `vistud_test` / `vistud_keys_test` only | Passed: verified decrypt, original types restored, migrate/encrypt again, note/title preserved (16 assertions). Not a production restore or an S9 scale result. |
| 2026-10-20 | S2b-only and combined S2a/S2b schema/data rollback and redeployment on Windows, named test schemas only | Passed in focused validation: refuses encrypted schema rollback, verified decrypt, original types restored, S2a stays encrypted during S2b-only rollback, both phases re-encrypt and preserve the note. No local deploy, production restore or S9 scale result. |

## Erasure ledger, backups and restore (S4b)

### Approved retention

These are maximum ages for **every copy**, including replicas, provider versions,
recycle bins, off-site copies and snapshots. Encrypt backups and restrict recovery
access to Abel and his privately designated recovery custodian.

| Store | Keep | Boundary |
|---|---|---|
| Main data database and canonical encrypted source files | 35 days | Daily consistent backups; include note images, recordings/pieces, extracted text, diagnostics and conversion receipts. Exclude rebuildable previews and all plaintext temps. |
| Separate learner-key database | 7 days | Daily encrypted independent backups, separate access/storage from data backups. Never bundle into a 35-day data archive or use `--all-databases`. |
| MySQL binlogs (including key changes) | 7 days | Expire archived and replicated logs too. A log capable of recovering a deleted key is a key backup. |
| Application, nginx, worker and diagnostic operational logs | 30 days | No request bodies, credentials or student text. Erasure removes the learner's queued/failed payloads immediately. Apply the age limit to archived logs too. |
| Independent erasure journal/checkpoint and immutable exports | Indefinitely | Content-free UUIDs/times only; never replace with a copy from a data/key backup. |
| Readable backups from before S2/S3 | Delete by verified conversion + 14 days | Exact UTC calendar date must be recorded and enforced; includes historical downloads, volume images and operator copies. |

The retained audit event history follows ADR 0004: keep events, anonymise the
account's identifiers/IP/user agent/details through erasure. It is distinct from
operational logs. Old main-database backups can retain identifying metadata until
their 35-day expiry; replay removes it before a restoration serves traffic.

Whole-machine/volume images containing the MySQL key database have a **7-day**
maximum, even if the host calls them a general server backup. Keep 35-day data
backups as separate logical database/source-file archives that exclude keys.
Erased content can remain decryptable in older data backups while a matching key
backup/binlog exists (up to 7 days). Never restore such a key to read erased work.
Deleting files is not guaranteed physical-media overwriting; provider versioning,
SSD snapshots, swap and crash-dump policies must meet the same retention limits.

Abel records this release worksheet **privately before reopening**; this phase
has not run conversion, so no actual conversion date is invented here:

- Verified S2/S3 full conversion and unrestricted verification completed (UTC): **required**.
- Delete every pre-encryption backup by (UTC calendar date = that date + 14 days): **required**.
- Backup locations/versioning/copy inventory, responsible custodian and scheduled deletion ticket: **required**.
- Deletion completed, all providers/copies checked, evidence/date/custodian: **required by the deadline**.

Do not reset the 14-day clock when another copy is made. If a provider cannot
expire an old readable/key-bearing copy within policy, resolve that before release.

### Two offline master-key copies

Losing the master or the separate key database without usable backups loses
student content permanently. Keep **two encrypted offline recovery kits** in
separate physical locations under Abel's control (Copy A and Copy B). Neither is
stored on the application server, in MySQL, in database archives or in git.
The private recovery register contains the exact locations, custodians, unlock
procedure, last verification date and sealed recovery access. Do not put those
addresses, passphrases or real keys in this document, a ticket or a chat.

Each kit contains the current master, previous masters still required by retained
key backups, the independent ledger authentication key and stable ledger UUID,
and necessary application-key recovery material while old credentials depend on
APP_KEY. Keep encryption/unlock material separate from the kit. Update and verify
both copies after rotation; retain an old master only while matching permitted
key backups require it. The ledger authentication key/UUID remain available for
its indefinite history and are independent of learner/master-key rotation.

To recover, Abel and the named custodian open a kit through that private register,
inject secrets directly into isolated FPM/CLI/worker environments, and verify a
synthetic/non-erased key and ciphertext round trip in scratch. Never echo a key,
pass it on a command line or copy it into config cache. If a kit fails, use the
other and replace/verify the damaged kit before release. Missing usable key or
ledger recovery material blocks reopening; never generate replacement learner keys.

### Independent durable ledger setup (first deployment only)

The live erasure journal is **outside the checkout, database and source-file
backup/restore sets**, on independently retained storage, preferably an off-host
mounted filesystem. Example path: `/var/lib/vistud-erasure-ledger`. Provision its
0700 directory for the dedicated application service account and verify file
locking, fsync, atomic rename and power-loss durability on the real server.
Reject an object-store mount without those guarantees. Replicate immutable exports
and their latest checkpoint to a separately controlled off-site destination.
Windows development uses only synthetic temporary directories; it makes no server
durability claim.

Privately supply `VISTUD_ERASURE_LEDGER_PATH`, stable non-secret UUID
`VISTUD_ERASURE_LEDGER_ID`, and an independent random 32-byte secret encoded as
`base64:` in **VISTUD_ERASURE_LEDGER_KEY** to all FPM/CLI/worker/scheduler processes.
Generate/provision that secret privately; no example real key is included here.
The authentication key is read from the process environment, never Laravel's
config cache. Do not derive it from a learner key or rotate it with the master.

With traffic offline and workers stopped, initialise **once**, only for first S4b
setup, then import the latest approved S4a database's requests/completions:

```sh
php artisan down
php artisan vistud:erasure:ledger init --offline-confirmed
php artisan vistud:erasure:ledger sync
php artisan vistud:erasure:ledger export --output=/independent-exports/NEW-UNIQUE-NAME.jsonl
php artisan vistud:erasure:ledger status
```

The directory must already exist. Init refuses existing ledger files. Never run
init after a restore, missing mount, damaged ledger or lost key. Recover the
existing latest independent journal/checkpoint instead. Commands print counts and
an authenticated `head`, never content or secrets. Export refuses overwrite.
Store the exported head/checkpoint and export date in the separate recovery
register/off-site control system, **not solely beside a historical data backup**.
A signed old export is still old; validity alone does not prove freshness.

New deletion intent is fsynced before the account transaction can acknowledge it;
job cleanup also requires durable intent. This intent is authoritative even if
the DB commit/response is lost: retry reuses its UUID/grace, and the ten-minute
pending scan recovers it. A failed response is not a cancellation of an already
durable deletion request. Completion is independently persisted after actual
cleanup/key destruction and before local completion commit. A crash anywhere in
that window remains replayable from intent alone.

The ten-minute retry scan repairs only an authenticated, unacknowledged append
beyond the saved checkpoint and imports missing local requests before queueing.
It never drops an acknowledged record. A corrupted committed prefix, wrong key,
missing checkpoint or unavailable independent mount fails closed. Preserve damaged
copies and recover from the latest independent export; do not trim a committed
record or initialise a replacement ledger. Monitor command failures, unfinished
requests and off-site replication/checkpoint freshness. Export/status/recover require no MySQL or backup master; `sync` alone imports
S4a database history. If publication was interrupted, `vistud:erasure:ledger recover`
can finish only an authenticated unacknowledged tail without the main database.
Take an immutable export
after a deletion batch and at least daily; live intents must already be durable
on the independent mount, so periodic exports are not the sole copy.

### Every restore: replay before any access

This applies to main DB, key DB, source files, replicas and whole-machine restores,
including **key-only or file-only** restores. A completed local flag is never
proof that a restored key or file is gone.

1. Enable maintenance at the edge and Laravel; stop all workers/scheduler and
   processing children. Work in scratch first. Isolate outbound mail/AI services.
   Do not restore over the independent mount or secrets. Do not run the pending
   queue or import/export from an untrusted old database as a substitute for replay.
2. Fetch the **latest** independent journal/checkpoint, immutable export and its
   authenticated head from the independent off-site control record. Verify the
   stable installation UUID/key, access and freshness with its custodian. Do not
   use an old ledger found inside the restored archive. Missing latest evidence
   blocks reopening; do not initialise an empty ledger.
3. Restore compatible encrypted data/source files and separately allowed key
   backups/binlogs into scratch, with correct master recovery material. Restore
   no plaintext processing temps. Apply compatible schema migrations as the
   configured owner, never MySQL root through development tooling.
4. Validate **all** ledger records/checkpoint and identities without changing
   restored data, then replay with the same independently obtained head:

   ```sh
   php artisan vistud:erasure:replay /independent-exports/LATEST.jsonl --head=AUTHENTICATED_HEAD --dry-run
   php artisan vistud:erasure:replay /independent-exports/LATEST.jsonl --head=AUTHENTICATED_HEAD --offline-confirmed
   ```

   `AUTHENTICATED_HEAD` is a non-secret 64-hex checkpoint, not a key. Replay requires
   Laravel maintenance and explicit confirmation that workers are stopped. It
   blocks every restored account named by an intent before erasing any of them,
   reuses S4a's order and key-last cleanup, and forces cleanup despite local
   completion flags. It never decrypts erased study content. Other learners and
   their keys/files remain intact. Replay can remove a restored last admin whose
   deletion was already authorised; arrange recovery with a new identity afterward.
5. Nonzero exit, deferred requests (the original three-hour grace remains),
   missing keys needed for non-erased learners, identity conflicts, corrupt or
   stale evidence: **keep traffic and workers stopped**. Repair the cause and
   retry. The same export resumes when only completion records advanced; if a
   new intent arrived, fetch a new export/head. A partial attempt may have blocked
   accounts or destroyed keys already, so never roll it back by restoring old keys.
6. Require exit 0 and zero deferred requests. Verify all named erased learners'
   keys, registered rows and private trees are absent, and unrelated learners'
   encrypted text/files still round-trip. Run `vistud:doctor`, relevant regression
   tests and the independent G3 drill when available. Record export head, counts,
   restore IDs, date and custodian privately. Only then restart scheduler/workers
   and reopen traffic. Repeat replay if any older key/file/data snapshot is applied.

Do this drill **monthly**, and after storage/encryption/backup policy changes.
Use an isolated copy sized appropriately for the server; Windows builder tests
are not a production restore or S9 performance pass. Record measured outcomes
in the drill log; no production drill is claimed by this implementation.
