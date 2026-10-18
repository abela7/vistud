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

## Log of drills

| Date | Drill | Result |
|---|---|---|
| | | |
