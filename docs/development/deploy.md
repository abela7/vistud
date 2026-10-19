# Deploying ViStud to vistud.app

How ViStud reaches production at **https://vistud.app** (decisions 2 and 7 of [docs/specs/mcp-connections-plan.md](../specs/mcp-connections-plan.md), section 5). The MCP server is `https://vistud.app/mcp`. That address never changes after launch: a change signs every connected AI app out.

`.app` is an HTTPS-only domain (HSTS preload): the site needs a certificate from the first request. Abel runs releases; nobody pastes secrets into a chat, a ticket or a commit.

## The server

A VPS with 2 vCPU and 4 GB is enough for one learner.

| Piece | Setting |
|---|---|
| OS packages | nginx, **PHP 8.4** FPM (8.4.1 or later; `vendor/composer/platform_check.php` refuses older) with `pdo_mysql`, `mbstring`, `intl`, `sodium`, `zip`, `gd`; MySQL 8.4; Composer 2; Node 22 for the build; LibreOffice for Office previews; supervisor; certbot |
| DNS | An `A` record for `vistud.app` to the VPS. If Cloudflare is used, `/mcp`, `/oauth/*` and `/.well-known/*` must be **DNS-only**: the proxy cuts calls at 125 s and has blocked Claude's token exchange (laravel/mcp issue #210) |
| TLS | Let's Encrypt (`certbot --nginx -d vistud.app`), renewed automatically |
| Redirects | `http://` to `https://` only, and no redirect to another host: Claude drops the `Authorization` header across hosts |
| Database users | Two MySQL users, as in [setup.md](setup.md#database-users): the schema owner for migrations, the restricted runtime user for the app |

### nginx

The usual Laravel site, plus for `/mcp` the time Claude allows a call (240 s):

```nginx
location = /mcp {
    try_files $uri /index.php?$query_string;
}
location ~ \.php$ {
    fastcgi_read_timeout 250s;   # /mcp calls; McpPrincipal sets PHP's limit to 230 s
    # ... the usual fastcgi_pass and params
}
```

### .env on the server

Copied privately, never committed. The values that differ from local:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://vistud.app
APP_KEY=        # the SAME key as the local install (see the go-live migration)
LOG_LEVEL=warning
QUEUE_CONNECTION=database
SESSION_SECURE_COOKIE=true
MCP_ENABLED=true
TUNNEL=0
```

### Processes

- **Queue worker** (supervisor): `php artisan queue:work --tries=3 --max-time=3600`. Required: without it big PDFs are never read (`ExtractFileText`), previews are never made, and the reader's jobs never run.
- **Scheduler** (cron, every minute): `* * * * * cd /var/www/vistud && php artisan schedule:run >> /dev/null 2>&1`. It runs session settling every ten minutes, `passport:purge` hourly, and the nightly prune jobs (`vistud:mcp:prune`, `vistud:db:orphans`, `vistud:engine:prune-threads`, the trash).
- **Passport keys**: made on the server with `php artisan passport:keys`, mode 0600, never copied from a laptop and never in git.

## Go-live migration (once)

Abel's study history is in the local MySQL and `storage/`. 2FA secrets, recovery codes and his own OpenRouter key are encrypted with `APP_KEY`, so the server must use the same key.

1. Locally: `.tools\backup-database.ps1`, and copy `storage/app` (the files, previews and texts).
2. On the server: restore the database dump and the files; put the local `APP_KEY` in the server's `.env`.
3. `php artisan migrate --database=mysql_owner --pretend`, read it, then `composer migrate`.
4. `php artisan passport:keys`, `php artisan config:cache`, `php artisan route:cache`, `php artisan view:cache`.
5. Check before cut-over: signing in with 2FA works; Settings › AI › the key's "test" passes; `curl -i -X POST https://vistud.app/mcp` answers **401** with a `WWW-Authenticate` header naming `https://vistud.app/.well-known/oauth-protected-resource/mcp`.
6. From then on, study on production only. The local copy is for development, with seeded or synthetic data.

## A release

Run on the server, as the deploy user:

```bash
cd /var/www/vistud
php artisan down --retry=30
mysqldump ... > ~/backups/vistud-$(date +%F-%H%M).sql   # or the backup script
git fetch origin && git merge --ff-only origin/claude/persistent-study-context-zsilo6
composer install --no-dev --optimize-autoloader
npm ci && npm run build
composer migrate
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
php artisan up
```

If a migration fails: `php artisan migrate:rollback --database=mysql_owner --step=1`, check out the previous commit, repeat from `composer install`.

## After the first release: connect the apps

- **claude.ai**: Customize › Connectors › Add custom connector, URL `https://vistud.app/mcp`. Sign in to ViStud, approve on the consent page. It then shows on the phone too.
- **ChatGPT** (Plus): Plugins › + › Add custom MCP server, the same URL. First check that the plan offers it at all (M6.2, ChatGPT step 1).
- **Antigravity CLI** (Google's test client): `serverUrl: "https://vistud.app/mcp"` in `~/.gemini/config/mcp_config.json`.

Gemini's redirect hosts are not in `config/mcp.php` yet: the first refused registration names them (plan §3.2).

## Restore drill (monthly)

Restore last night's backup into a scratch database on the server, run `php artisan vistud:doctor` against it, and delete it. Note the date in [operations.md](operations.md).
