-- Local development and test databases and users (docs/development/setup.md).
-- Run once as a MySQL administrator, for example:
--   sudo mysql < database/scripts/local-mysql-users.sql
-- CI runs the same script.
--
-- Two users, on purpose:
--   vistud_owner  runs migrations and owns the schema. It can grant table
--                 privileges to the runtime user (WITH GRANT OPTION).
--   vistud_app    is what the application and the tests connect as. It gets
--                 per-table privileges from `php artisan vistud:db:grants`,
--                 which runs automatically after every migration. On
--                 audit_log it can only SELECT and INSERT (ADR 0003 §10.4).
--
-- The passwords are for local use only. Never reuse them anywhere else.

CREATE DATABASE IF NOT EXISTS vistud CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS vistud_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'vistud_owner'@'%' IDENTIFIED BY 'owner-local-only';
CREATE USER IF NOT EXISTS 'vistud_app'@'%' IDENTIFIED BY 'app-local-only';

GRANT ALL PRIVILEGES ON vistud.* TO 'vistud_owner'@'%' WITH GRANT OPTION;
GRANT ALL PRIVILEGES ON vistud_test.* TO 'vistud_owner'@'%' WITH GRANT OPTION;

FLUSH PRIVILEGES;
