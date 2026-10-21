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

Proposed retention, **not yet approved by Abel**: daily encrypted data/file backups
for 30 days; separate encrypted key-database backups for 7 days; a monthly restore
drill of both, with the erasure ledger re-applied before any access. S4 must implement
that ledger and confirm these periods. Backups of plaintext data taken before S2/S3
remain readable: after the backfills are verified, Abel must record and enforce an
actual calendar deletion date for those copies. S1 does not encrypt existing text/files.

Recovery: take the app offline, stop workers, recover the master from the offline
copy into the process environment, restore the separate key database in scratch,
verify keys and a content round trip, and only then plan a live restore with Abel.
Once S4 exists, reapply its erasure ledger first. Never restore an erased learner's
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
are still readable; the S4 retention/deletion decision above remains pending.

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
deletes it. Deleting a temp file is not secure media erasure; S4 must still
approve retention and a calendar date for removing readable old backups.

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
