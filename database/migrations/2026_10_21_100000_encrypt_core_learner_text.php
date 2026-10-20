<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const COLUMNS = [
        'notes' => ['title' => 'varchar(200) NOT NULL'],
        'note_versions' => ['title' => 'varchar(200) NOT NULL', 'doc' => 'longtext NOT NULL'],
        'questions' => ['text' => 'varchar(1000) NOT NULL', 'answer' => 'text NULL'],
        'findings' => ['text' => 'varchar(500) NOT NULL', 'locator' => 'varchar(60) NULL'],
        'flashcards' => ['front' => 'varchar(500) NOT NULL', 'back' => 'varchar(1000) NOT NULL'],
        'tutor_memories' => ['text' => 'varchar(200) NOT NULL'],
        'study_sessions' => ['summary' => 'text NULL', 'checkpoint' => 'text NULL', 'topic_log' => 'json NULL', 'tutoring' => 'json NULL'],
        'engine_threads' => ['title' => 'varchar(120) NULL', 'summary' => 'text NULL'],
        'engine_messages' => ['content' => 'mediumtext NULL', 'tool_calls' => 'json NULL', 'attachments' => 'json NULL', 'effects' => 'json NULL', 'reasoning' => 'json NULL'],
        'journal_entries' => ['body' => 'json NOT NULL', 'links' => 'json NOT NULL'],
        'journal_content' => ['text' => 'longtext NOT NULL'],
        'journal_refs' => ['locator' => 'varchar(128) NULL'],
        'projection_snapshots' => ['snapshot' => 'json NOT NULL'],
        'outbox' => ['payload' => 'json NOT NULL', 'last_error' => 'varchar(255) NULL'],
        'mcp_confirmations' => ['input' => 'json NOT NULL'],
        'class_sessions' => ['title' => 'varchar(200) NOT NULL', 'teacher' => 'varchar(120) NULL', 'language' => 'varchar(40) NULL', 'problem' => 'varchar(200) NULL'],
        'class_transcripts' => ['text' => 'longtext NOT NULL', 'segments' => 'json NOT NULL', 'language' => 'varchar(40) NULL'],
        'class_reviews' => ['body' => 'json NOT NULL'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $fields) {
            $clauses = ['ADD `content_version` TINYINT UNSIGNED NOT NULL DEFAULT 0'];
            if (in_array($table, ['journal_refs', 'outbox'], true)) {
                $clauses[] = 'ADD `content_identity` CHAR(36) NULL UNIQUE';
            }
            foreach ($fields as $field => $original) {
                // LONGTEXT (4 GiB) safely covers nested base64 expansion: a 2 MB
                // note is about 3.56 MB; MEDIUMTEXT input can exceed MEDIUMTEXT
                // after encryption. No old capacity is silently reduced.
                $clauses[] = "MODIFY `{$field}` LONGTEXT ".(str_contains($original, 'NOT NULL') ? 'NOT NULL' : 'NULL');
            }
            DB::statement('ALTER TABLE `'.$table.'` '.implode(', ', $clauses));
        }
    }

    public function down(): void
    {
        // Explicit verified --decrypt comes first. Never truncate ciphertext.
        foreach (self::COLUMNS as $table => $fields) {
            if (DB::table($table)->where('content_version', '!=', 0)->exists()) {
                throw new RuntimeException('Decrypt and verify core content under maintenance before rollback.');
            }
            foreach ($fields as $field => $original) {
                if (str_starts_with($original, 'varchar')) {
                    preg_match('/varchar\((\d+)\)/', $original, $match);
                    $invalid = DB::table($table)->whereRaw('CHAR_LENGTH(`'.$field.'`) > ?', [(int) $match[1]])->exists();
                } elseif (str_starts_with($original, 'json')) {
                    $invalid = DB::table($table)->whereNotNull($field)->whereRaw('NOT JSON_VALID(`'.$field.'`)')->exists();
                } else {
                    $limit = str_starts_with($original, 'text ') ? 65535 : (str_starts_with($original, 'mediumtext ') ? 16777215 : 4294967295);
                    $invalid = DB::table($table)->whereRaw('OCTET_LENGTH(`'.$field.'`) > ?', [$limit])->exists();
                }
                if ($invalid) {
                    throw new RuntimeException('Content would not fit its original column; rollback refused.');
                }
            }
        }
        foreach (self::COLUMNS as $table => $fields) {
            $clauses = ['DROP `content_version`'];
            if (in_array($table, ['journal_refs', 'outbox'], true)) {
                $clauses[] = 'DROP `content_identity`';
            }
            foreach ($fields as $field => $original) {
                $clauses[] = "MODIFY `{$field}` {$original}";
            }
            DB::statement('ALTER TABLE `'.$table.'` '.implode(', ', $clauses));
        }
    }
};
