#!/bin/bash
# One-time setup of ViStud in its sealed box on this server (see docs/development/docker-deploy.md).
#
#   cd /opt/vistud/src && bash deploy/docker/setup.sh
#
# Safe to run again: it never replaces the settings file (which holds the master key), never touches other sites
# or the web server, and prints no secret. It only uses /opt/vistud and Docker containers named "vistud-*".
set -euo pipefail

ROOT=/opt/vistud
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ENVFILE="$ROOT/vistud.env"
DATA="$ROOT/data"
SITE="${VISTUD_HOST:-vistud.app}"

say() { printf '\n== %s\n' "$*"; }
die() { printf '\nSTOP: %s\n' "$*" >&2; exit 1; }

[ "$(id -u)" = 0 ] || die "Run this as the administrator (root)."
[ "$SRC" = "$ROOT/src" ] || die "The code must be in $ROOT/src (it is in $SRC)."
command -v docker > /dev/null || die "Docker is not installed on this server."
docker compose version > /dev/null 2>&1 || die "Docker Compose is not installed on this server."
command -v openssl > /dev/null || die "openssl is missing."

say "Checking the server has room"
available_mb=$(awk '/MemAvailable/ {print int($2/1024)}' /proc/meminfo)
free_gb=$(df -BG --output=avail "$ROOT" 2> /dev/null | tail -1 | tr -dc '0-9')
echo "Free memory: ${available_mb} MB. Free disk: ${free_gb:-?} GB."
[ "$available_mb" -ge 2500 ] || die "Less than 2.5 GB of memory is free right now. Stop and ask for help before going on."
[ "${free_gb:-0}" -ge 20 ] || die "Less than 20 GB of disk is free."
if ss -ltn 2> /dev/null | awk '{print $4}' | grep -q '[:.]8081$'; then
  docker ps --format '{{.Names}}' | grep -q '^vistud-app-1$' || die "Port 8081 is already used by something else."
fi

say "Making the folders (data stays outside the code)"
mkdir -p "$DATA"/mysql "$DATA"/storage "$DATA"/ledger "$DATA"/exports
chown -R 33:33 "$DATA"/storage "$DATA"/exports "$DATA"/ledger
chmod 700 "$DATA"/ledger

DBENV="$ROOT/db.env"
if [ ! -f "$ENVFILE" ]; then
  [ ! -f "$DBENV" ] || die "$DBENV exists but $ENVFILE does not. Do not guess: ask for help."
  say "Creating the settings files with new secrets (nothing is printed)"
  umask 077
  b64() { echo "base64:$(openssl rand -base64 32)"; }
  hex() { openssl rand -hex 24; }
  owner_pw="$(hex)"
  app_pw="$(hex)"
  # The database container gets its own file: the web, worker and scheduler never see the database root password.
  cat > "$DBENV" <<EOF
MYSQL_ROOT_PASSWORD=$(hex)
VISTUD_DB_OWNER_PASSWORD=$owner_pw
VISTUD_DB_APP_PASSWORD=$app_pw
EOF
  chmod 600 "$DBENV"
  cat > "$ENVFILE" <<EOF
APP_NAME=ViStud
APP_ENV=production
APP_DEBUG=false
APP_URL=https://$SITE
APP_KEY=$(b64)
APP_MAINTENANCE_DRIVER=file
VISTUD_HOST=$SITE
TUNNEL=0
MCP_ENABLED=true
LOG_CHANNEL=daily
LOG_DAILY_DAYS=30
LOG_LEVEL=warning
DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=vistud
DB_USERNAME=vistud_app
DB_PASSWORD=$app_pw
DB_OWNER_USERNAME=vistud_owner
DB_OWNER_PASSWORD=$owner_pw
DB_RUNTIME_HOSTS=%
DB_KEYS_DATABASE=vistud_keys
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=database
CACHE_STORE=database
FILESYSTEM_DISK=local
MAIL_MAILER=log
MAIL_FROM_ADDRESS=no-reply@$SITE
MAIL_FROM_NAME=ViStud
VISTUD_MASTER_KEY=$(b64)
VISTUD_PREVIOUS_MASTER_KEY=
VISTUD_ERASURE_LEDGER_PATH=/var/lib/vistud-erasure-ledger
VISTUD_ERASURE_LEDGER_ID=$(cat /proc/sys/kernel/random/uuid)
VISTUD_ERASURE_LEDGER_KEY=$(b64)
VISTUD_OFFICE_AT_ONCE=2
EOF
  chmod 600 "$ENVFILE"
  umask 022
else
  say "The settings file already exists: keeping it exactly as it is"
fi

cat > /usr/local/bin/vistud-compose <<EOF
#!/bin/bash
# Runs docker compose for ViStud with its settings file.
exec docker compose -f $SRC/deploy/docker/compose.yml "\$@"
EOF
chmod 755 /usr/local/bin/vistud-compose

if command -v git > /dev/null && git -C "$SRC" rev-parse HEAD > /dev/null 2>&1; then
  git -C "$SRC" rev-parse HEAD > "$SRC/REVISION"
  chmod 644 "$SRC/REVISION"
fi

say "Building the box (about 10 to 20 minutes the first time; it runs at low priority so the other sites keep going)"
nice -n 19 ionice -c3 vistud-compose build app

say "Starting the database"
vistud-compose up -d --wait db

artisan() { vistud-compose run --rm -T app php artisan "$@"; }

say "Preparing the databases"
artisan migrate --database=mysql_keys_owner --path=database/key-migrations --force
artisan migrate --database=mysql_owner --force

if [ ! -f "$DATA/storage/oauth-private.key" ]; then
  say "Making the sign-in keys for AI apps"
  artisan passport:keys
fi

if [ ! -f "$ROOT/.ledger-initialised" ]; then
  say "Starting the independent erasure record (first setup only)"
  artisan down
  artisan vistud:erasure:ledger init --offline-confirmed
  artisan vistud:erasure:ledger status
  artisan up
  touch "$ROOT/.ledger-initialised"
fi

say "Starting ViStud"
vistud-compose up -d --wait

cat <<MSG

== Done. ViStud is running inside its box on this server, on 127.0.0.1:8081 only (not on the internet yet).

Next, in this order:
  1. Connect the website address to the box (CyberPanel step in docs/development/docker-deploy.md).
  2. Make your account:  vistud-compose exec app php artisan vistud:account:create YOU@EXAMPLE.COM
                         vistud-compose exec app php artisan vistud:admin:grant YOU@EXAMPLE.COM
  3. Copy the master key to a password manager. Show it ONLY on your own screen:
                         grep '^VISTUD_MASTER_KEY=' $ENVFILE
     If it is lost, all encrypted data is lost for good. Never paste it into a chat.
MSG
