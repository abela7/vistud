# ViStud in a sealed Docker box (for a server that already hosts other sites)

Use this when the server runs other websites (for example under CyberPanel/OpenLiteSpeed) and you do not want ViStud's
needs (PHP 8.4, MySQL 8.4, LibreOffice, ffmpeg, a background worker) to change what those sites run on. Everything ViStud
needs is inside four containers. The other sites, their PHP and their database are never touched. Removing ViStud is
`vistud-compose down` and deleting `/opt/vistud`.

[deploy.md](deploy.md) and [go-live-checklist.md](go-live-checklist.md) describe the same release on a clean server with
nginx; the data safety rules in [operations.md](operations.md) apply unchanged (backups, the master key, the erasure ledger).

## What runs

| Container | Does | Memory cap |
|---|---|---|
| `db` | MySQL 8.4. Not reachable from outside the box. | 1 GB |
| `app` | nginx + PHP-FPM, published only on `127.0.0.1:18081` | 1 GB |
| `worker` | the queue (reads PDFs and Office files, runs the AI jobs, transcribes recordings) | 1.5 GB |
| `scheduler` | the scheduled jobs | 256 MB |

Temporary files, uploads and nginx's upload buffer live in memory-backed storage (`/run/vistud-tmp`), as the operations
guide requires. Data sits on the server's disk under `/opt/vistud/data` (`mysql`, `storage`, `ledger`, `exports`); the
settings files are `/opt/vistud/vistud.env` (everything the app needs, mode 600; the master key is generated there on first
setup) and `/opt/vistud/db.env` (the database's own passwords, which the app never sees).

## First setup (as root, on the server)

```bash
mkdir -p /opt/vistud && cd /opt/vistud
git clone https://github.com/abela7/vistud.git src
cd src && git checkout claude/persistent-study-context-zsilo6    # until it is merged to the main branch
bash deploy/docker/setup.sh
```

The script checks free memory and disk, makes the folders, creates the settings file **only if it does not exist**,
builds the box, migrates both databases, makes the sign-in keys for AI apps and starts the independent erasure record.
It prints no secret.

## Connect the web address (CyberPanel / OpenLiteSpeed)

The site `vistud.app` already exists in CyberPanel with its certificate. Make it forward everything to the box:
*Websites → List Websites → Manage (vistud.app) → vHost Conf*, and add at the end (remove any existing `context /` first):

```
context /.well-known/acme-challenge {
  location                /home/vistud.app/public_html/.well-known/acme-challenge
  allowBrowse             1
  addDefaultCharset       off
}

extprocessor vistud_box {
  type                    proxy
  address                 127.0.0.1:18081
  maxConns                100
  pcKeepAliveTimeout      60
  initTimeout             60
  retryTimeout            0
  respBuffer              0
}

context / {
  type                    proxy
  handler                 vistud_box
  addDefaultCharset       off
}
```

`respBuffer 0` lets chat answers stream. The first context keeps the certificate renewal working. Save, then restart the
web server from CyberPanel.

## Day to day

```bash
vistud-compose ps                                   # what is running
vistud-compose logs -f app                          # the web side (use worker / scheduler / db for the others)
vistud-compose exec app php artisan vistud:gate:second-learner
bash /opt/vistud/src/deploy/docker/update.sh        # a newer version: fetch, rebuild, migrate, restart
```

Secrets: never print `/opt/vistud/vistud.env` into a chat. Copy the `VISTUD_MASTER_KEY=` line and the two
`VISTUD_ERASURE_LEDGER_*` lines to a password manager from your own screen; losing the master key loses all encrypted data.

## What is not proven yet

This setup has been written but not run on a server. The first build is the test. Known limits: mail is set to `log` (no
email is sent until a real mail transport is configured), and the gate's "acceptance run" needs the test tools, which the
box does not contain: record it on a development machine for the exact commit and copy `storage/app/gate/acceptance.json`
into `/opt/vistud/data/storage/app/gate/`.
