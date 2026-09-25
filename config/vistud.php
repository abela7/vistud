<?php

/*
| ViStud settings. Every value here is documented in
| docs/architecture/conventions.md. Change a default only through the
| contract-change process (docs/architecture/contracts.md).
*/

return [

    'database' => [
        // The runtime user the application connects as, and the hosts it
        // connects from. `php artisan vistud:db:grants` gives it per-table
        // privileges after every migration.
        'runtime_user' => env('DB_USERNAME'),
        'runtime_hosts' => array_values(array_filter(explode(',', (string) env('DB_RUNTIME_HOSTS', '%')))),

        // The connection that owns the schema and runs migrations.
        'owner_connection' => 'mysql_owner',

        // Tables the runtime user may only read and insert into (ADR 0003 §10.4).
        'append_only_tables' => ['audit_log'],

        // Tables the runtime user may only read.
        'read_only_tables' => ['migrations'],
    ],

    'access' => [
        // "Recent password confirmation" (ADR 0003 §10.2): 10 minutes.
        'password_confirmation_seconds' => 600,
    ],

    'invitations' => [
        'ttl_hours' => 72,
    ],

    'appearance' => [
        // The default light and dark themes. In "system" mode the browser's
        // preference picks between them before the first paint
        // (ADR 0003 §6.4, DESIGN.md §3.6).
        'light' => 'vistud-light',
        'dark' => 'vistud-dark',
    ],

];
