<?php

namespace App\Platform\Database;

/** The S2a storage manifest. S2b adds the remaining study content. */
final class ContentColumns
{
    public const VERSION = 1;

    /** Original MySQL declarations, also used by the verified schema rollback. */
    public const S2A = [
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

    public static function fields(string $table): array
    {
        return array_keys(self::S2A[$table] ?? []);
    }

    /** Physical primary keys, within one learner. */
    public static function primary(string $table): array
    {
        return match ($table) {
            'journal_content' => ['entry_id', 'field'],
            'projection_snapshots' => ['rules_version'],
            default => ['id'],
        };
    }

    /** Auto-increment rows reserve an immutable UUID before their INSERT. */
    public static function identity(string $table): array
    {
        return match ($table) {
            'journal_refs' => ['entry_id', 'role', 'ref_type', 'ref_id', 'content_identity'],
            'outbox' => ['content_identity'],
            default => self::primary($table),
        };
    }
}
