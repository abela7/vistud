#!/bin/bash
# Puts a newer version of ViStud on the server (the box already exists). Run as root:
#   cd /opt/vistud/src && bash deploy/docker/update.sh
# It keeps the data and the settings file, rebuilds the box from the newest code and updates the databases.
set -euo pipefail

SRC=/opt/vistud/src
cd "$SRC"
[ "$(id -u)" = 0 ] || { echo "Run this as root." >&2; exit 1; }

git fetch origin
git merge --ff-only "origin/$(git rev-parse --abbrev-ref HEAD)"
git rev-parse HEAD > REVISION
chmod 644 REVISION

nice -n 19 ionice -c3 vistud-compose build app

artisan() { vistud-compose run --rm -T app php artisan "$@"; }
artisan down --retry=30
artisan migrate --database=mysql_keys_owner --path=database/key-migrations --force
artisan migrate --database=mysql_owner --force
vistud-compose up -d --wait
artisan up
echo "ViStud is updated to $(cat REVISION)."
