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

## Log of drills (results)

| Date | Drill | Result |
|---|---|---|
| | | |
