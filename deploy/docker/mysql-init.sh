#!/bin/bash
# Runs once, the first time the database container starts with an empty data folder. It makes ViStud's two
# databases (content and keys) and its two users: the owner (changes the structure, used only for updates) and the
# app (day-to-day, restricted: its table rights are given by `php artisan vistud:db:grants` after each update).
# Mirrors database/scripts/local-mysql-users.sql, with real passwords from the settings file.
set -euo pipefail

: "${VISTUD_DB_OWNER_PASSWORD:?}"
: "${VISTUD_DB_APP_PASSWORD:?}"

mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" <<SQL
CREATE DATABASE IF NOT EXISTS vistud CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS vistud_keys CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'vistud_owner'@'%' IDENTIFIED BY '${VISTUD_DB_OWNER_PASSWORD}';
CREATE USER IF NOT EXISTS 'vistud_app'@'%' IDENTIFIED BY '${VISTUD_DB_APP_PASSWORD}';

GRANT ALL PRIVILEGES ON vistud.* TO 'vistud_owner'@'%' WITH GRANT OPTION;
GRANT ALL PRIVILEGES ON vistud_keys.* TO 'vistud_owner'@'%' WITH GRANT OPTION;
GRANT SELECT, INSERT, UPDATE, DELETE ON vistud_keys.* TO 'vistud_app'@'%';
FLUSH PRIVILEGES;
SQL
