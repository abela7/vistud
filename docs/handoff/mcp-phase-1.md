# ViStud · M6.1 (The door): handoff

Branch `claude-latest`. The plan is [docs/specs/mcp-connections-plan.md](../specs/mcp-connections-plan.md) (section 4, M6.1; section 3.2 and 3.4 for the design). M6.1 lets an AI app sign in to ViStud as the student and ask one question, "who am I in ViStud". There are no study tools yet: those are M6.2 (reads) and M6.3 (sessions and writes).

## What was built

**Packages and tokens**
- `laravel/passport` 13.10.0 and `laravel/mcp` 1.0.2.
- Migration `2026_10_18_100000_create_oauth_tables`: Passport's tables with `user_id` as a UUID and the client owner as a UUID morph. No device-code table: that grant is switched off (`Passport::$deviceCodeGrantEnabled = false`).
- An `api` guard with the `passport` driver (`config/auth.php`), used only by `/mcp`.
- `App\Models\User` implements `OAuthenticatable` with `HasApiTokens`.
- Access tokens last an hour, refresh tokens 30 days; `passport:purge` runs hourly. `php artisan passport:keys` makes the keys in `storage/` (git-ignored).

**Routes** (`routes/ai.php`, loaded by laravel/mcp)
- `Mcp::oauthRoutes()`: `/.well-known/oauth-protected-resource/mcp`, `/.well-known/oauth-authorization-server`, `POST /oauth/register` (throttled per IP, `config('mcp.register_per_minute')`).
- `POST /mcp` with, in order: an IP throttle (300 a minute), `auth:api`, Passport's `CheckToken` for `mcp:use`, `App\Http\Middleware\McpPrincipal`, `throttle:mcp` (60 calls a minute per student, keyed on the learner so a reconnect does not reset it).
- `POST /connections/approve` (web + auth): ViStud's consent step.
- `config/mcp.php` allows redirects only to `https://claude.ai`, `https://chatgpt.com`, `https://antigravity.google` and loopback (`localhost`, `127.0.0.1`, any port). Gemini's relay hosts are not guessed (plan §3.2).

**Who is calling**
- `PrincipalFactory::forToken()`: channel `mcp`, area student, the Student role only, no password confirmation. An admin who also studies can never pass `Guard::admin()` with a token.
- `McpPrincipal`: 503 when `MCP_ENABLED=false` (the kill switch); 403 for a foreign `Origin` (an absent one is fine); 403 with no sign-in challenge for a suspended or deleted account; 403 `connection_not_consented` (audited `connection.denied`) when the token's app has no live connection. It marks the connection used (once a minute at most) and calls `LearnerKeys::unlock()` (new, a no-op until per-learner encryption, M6.9).

**The server**: `App\Mcp\Servers\ViStudServer` with one tool, `me` (`App\Mcp\Tools\MeTool`): the first name, a masked email (`a••••@example.test`) and the number of courses. Read-only, all four hints explicit. `me` is MCP-native and is the one tool that names the student (written into `contracts.md`).

**Connections**
- Migration `2026_10_18_110000_create_mcp_connections`: `mcp_connections`, `mcp_calls` and `mcp_confirmations`, all in `LearnerTables::TABLES`; `mcp_calls` and `mcp_confirmations` cascade from `mcp_connections`. `mcp_calls` is written from M6.2, `mcp_confirmations` from M6.5.
- `App\Mcp\Connections` (new): consent, list, find, disconnect, disconnect all; for server code, `live`, `touch`, `revokeEverything`, `revokeOne`, `revokeTokens`. Cutting a connection revokes its access tokens, refresh tokens and unused codes in one transaction and deletes its open confirmations.
- `App\Mcp\Provider` (new): Anthropic, OpenAI, Google, "a program on this computer" or another app, from the redirect host, fixed at consent.
- The consent page (`resources/views/mcp/authorize.blade.php`) is shown on **every** sign-in (`App\Mcp\Http\AuthorizeConnection` overrides Passport's skip), with `X-Frame-Options: DENY` and `frame-ancestors 'none'`, no CSP `form-action`. It names the company and the redirect host, says what leaves ViStud, offers Read only / Read and write (default) / Read, write, and changes you confirm, and says "Only approve if you just clicked Connect". A loopback client is "a program on this computer (for example Claude Code)".
- `App\Mcp\Http\ApproveConnection` checks the page's token, writes the connection with the chosen level, then hands over to Passport. A code from Passport's own approve route has no connection, so its token is refused.

**Connections screen**: Settings › AI › Connected AI apps (`App\Livewire\Study\Connections`): grouped by company, each with its level and last use, a note when it has been unused for 30 days, and Disconnect; "Disconnect all AI apps" asks for the password first; the address to add when nothing is connected; disconnected apps folded below. Hidden for an account that is only an admin.

**Revocation**: a suspension, a deletion request (both through `Accounts::endSessions`), a 2FA reset and a new password (Fortify's update and reset actions) cut every connection at once (`App\Jobs\RevokeConnections`, run with `dispatchSync`). `php artisan vistud:mcp:prune` (nightly) cuts connections unused for 90 days and deletes call records older than 400 days.

**Tunnel guard**: `App\Http\Middleware\McpTunnelOnly` (global). With `TUNNEL=1`, every path except `/mcp`, `/.well-known/*`, `/oauth/*`, login, 2FA and `/connections/approve` answers 404, and the app refuses to run while `APP_DEBUG` is on. Proxies are trusted only with `TUNNEL=1`.

**Audit**: `connection.consented`, `connection.revoked`, `connection.denied`; `mcp.rate_limited` is reserved for M6.3. No row per call.

**Tests (new)**: `tests/Feature/Mcp/OAuthDiscoveryTest` (a real registration, consent, PKCE code exchange for a UUID user, then `me`), `ConsentTest`, `UnauthenticatedTest`, `PrincipalTest`, `TokenKindsTest` (only `/mcp` takes a Passport token; the nightly upkeep), `TunnelGuardTest`, `tests/Feature/Web/ConnectionsScreenTest`. Helper: `tests/Concerns/ConnectsAiApps`.

## What changed for the in-app tutor and the screens

Nothing in the tutor. Settings › AI has one new panel. A new password, a 2FA reset, a suspension or a deletion request now also signs every AI app out.

## What Abel tests (plain steps)

1. In a terminal in the project: `php artisan serve`.
2. In another terminal: `claude mcp add --transport http vistud http://127.0.0.1:8000/mcp`. Claude Code opens the browser: sign in to ViStud, and on the consent page check that it says *a program on this computer*, then press **Connect**.
3. In Claude Code ask: "who am I in ViStud". It answers with your first name, a masked email and your number of courses.
4. In ViStud, Settings › AI › Connected AI apps shows it. Press **Disconnect**. Ask Claude Code again: it asks you to sign in again.

claude.ai on the web or phone needs a public address (the tunnel test in `docs/development/setup.md`, or the server of M6.2). Do it only with `APP_DEBUG=false`, `TUNNEL=1` and a database with synthetic data.

## What is left or deferred

- **A new connection's email and in-app notice** (plan §3.2): not built. The consent page and the Connections screen are the record for now.
- **Refresh-token reuse** (`RefreshTokenReuse`, plan §3.2): not built. Passport rotates refresh tokens and refuses a used one, but it gives no hook to tell a reuse from an expiry; revoking the whole connection on reuse needs a check against Passport's token endpoint. Open question for M6.2.
- **`_meta["openai/profile"]` on `me`**: with the ChatGPT probes of M6.2.
- **The claude.ai tunnel test** (Abel check 3 of the plan): not run here.
- **Gemini's redirect hosts**: added from the first refused registration (plan §3.2).

## Rollback order

1. `php artisan migrate:rollback --database=mysql_owner --step=2` drops the connection tables, then Passport's tables.
2. Revert the M6.1 commit. `composer remove laravel/passport laravel/mcp` if the packages should go too.
3. Delete `storage/oauth-private.key` and `storage/oauth-public.key`.

## Message for the local tester

```
Please bring your copy up to date with M6.1 (The door). Do exactly these steps and nothing else.
Important: do not open the AI settings or AI engine pages, and do not read, write or paste any key or token anywhere.

1. git fetch origin claude-latest
2. git checkout claude-latest
3. git merge --ff-only origin/claude-latest
   (if it says it cannot fast-forward, stop and tell me; do not force anything)
4. composer install
5. composer migrate
   (two new migrations: 2026_10_18_100000_create_oauth_tables and 2026_10_18_110000_create_mcp_connections; never use migrate:fresh)
6. php artisan passport:keys
7. npm run build
8. Run only these tests, and tell me the pass/fail counts and the text of any failure:
   .tools\php\php.exe vendor\bin\phpunit tests/Feature/Mcp tests/Feature/Web/ConnectionsScreenTest.php tests/Architecture/LearnerIsolationTest.php tests/Feature/Identity

Do not push anything.
```
