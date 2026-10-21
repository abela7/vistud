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

        // Tables the runtime user may only read and insert into (ADR 0003 §10.4): the audit log, and the journal, whose
        // entries are never changed, only followed by others (ADR 0002). Rebuilding journal_refs, and erasing a
        // learner, run as the owner.
        'append_only_tables' => ['audit_log', 'journal_entries', 'journal_refs', 'journal_blocks', 'erasure_requests'],

        // Tables the runtime user may read, insert into and delete from, but never update: a journal entry's text and
        // mentions, which a redaction deletes (ADR 0002 §10) and nothing rewrites.
        'no_update_tables' => ['journal_content', 'journal_mentions'],

        // Tables the runtime user may only read.
        'read_only_tables' => ['migrations', 'erasure_log'],
    ],

    'access' => [
        // "Recent password confirmation" (ADR 0003 §10.2): 10 minutes.
        'password_confirmation_seconds' => 600,
    ],

    'erasure' => [
        // Includes 30-minute processing plus expiry and an hourly janitor pass.
        'delay_seconds' => 10800,
    ],

    'files' => [
        // Where uploaded files are kept: a private disk, never served
        // directly (docs/specs/workspaces.md step 4).
        'disk' => env('VISTUD_FILES_DISK', 'local'),
        'derived_disk' => env('VISTUD_DERIVED_DISK', 'derived'),
        'temp_root' => env('VISTUD_TEMP_ROOT', sys_get_temp_dir().'/vistud-'.substr(hash('sha256', base_path()), 0, 12)),
        // The largest file a student can upload. PHP's upload_max_filesize
        // and post_max_size must allow it too; the lower limit applies.
        'max_bytes' => (int) env('VISTUD_FILES_MAX_BYTES', 25 * 1024 * 1024),
        // Everything one student can store, the trash included.
        'quota_bytes' => (int) env('VISTUD_FILES_QUOTA_BYTES', 2 * 1024 * 1024 * 1024),
        // LibreOffice's soffice, which turns Word, PowerPoint and Excel files into a PDF to show (App\Study\FilePreviews).
        // Empty: found where LibreOffice installs itself, or on the PATH. "none": no previews.
        'office' => env('VISTUD_OFFICE_BINARY'),
        // ffmpeg, which cuts a class recording into pieces for transcription (App\Study\AudioPieces).
        'ffmpeg' => env('VISTUD_FFMPEG_BINARY', 'ffmpeg'),
        // How many LibreOffice conversions may run at once (each about 200 MB for a few seconds); more wait their turn.
        'office_at_once' => (int) env('VISTUD_OFFICE_AT_ONCE', 2),
    ],

    'invitations' => [
        'ttl_hours' => 72,
    ],

    'engine' => [
        // The language model behind the built-in chat (docs/specs/study-memory.md §6): any service that speaks
        // the OpenAI chat format. OpenRouter by default, one key for every model; the student picks the models.
        'url' => env('VISTUD_ENGINE_URL', 'https://openrouter.ai/api/v1'),
        // The key the owner holds. Never in the database, never on a screen.
        'key' => env('VISTUD_ENGINE_KEY'),
        // Sent as the app's name and address (OpenRouter's attribution headers; harmless elsewhere).
        'app_name' => env('APP_NAME', 'ViStud'),
        'app_url' => env('APP_URL', 'http://localhost'),
        // The models a student starts with, until they choose their own (an id as the service names it).
        // One per role (App\Engine\Role). The old VISTUD_ENGINE_QUICK_MODEL still sets the reader's.
        'tutor_model' => env('VISTUD_ENGINE_TUTOR_MODEL', ''),
        'reader_model' => env('VISTUD_ENGINE_READER_MODEL', env('VISTUD_ENGINE_QUICK_MODEL', '')),
        'helper_model' => env('VISTUD_ENGINE_HELPER_MODEL', ''),
        // Seconds to wait for a reply; how long the list of models is kept; the most look-ups in one turn;
        // the size of a chat (characters) past which its oldest turns are folded into a summary, and how
        // many messages stay whole when that happens.
        'timeout' => (int) env('VISTUD_ENGINE_TIMEOUT', 120),
        'models_cache_hours' => 24,
        'tool_rounds' => 6,
        // The model that writes a class recording down (App\Engine\Jobs\TranscribeClass): one that takes audio.
        'audio_model' => env('VISTUD_AUDIO_MODEL', 'google/gemini-2.5-flash'),
        'fold_at' => 60_000,
        'keep_recent' => 8,
    ],

    'appearance' => [
        // The default light and dark themes. In "system" mode the browser's
        // preference picks between them before the first paint
        // (ADR 0003 §6.4, DESIGN.md §3.6).
        'light' => 'vistud-light',
        'dark' => 'vistud-dark',
        // The themes a student chooses between in Settings > Appearance: each is a light and a dark
        // theme from resources/themes, and the mode (system, light or dark) picks between the two.
        'themes' => [
            'vistud' => ['name' => 'ViStud', 'light' => 'vistud-light', 'dark' => 'vistud-dark'],
            'blossom' => ['name' => 'Blossom', 'light' => 'blossom', 'dark' => 'blossom-dark'],
            'ember' => ['name' => 'Ember', 'light' => 'ember', 'dark' => 'vistud-dark'],
        ],
    ],

];
