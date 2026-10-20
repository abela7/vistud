<?php

namespace App\Platform\Database;

/** Personal text storage manifest. Historical migrations own their frozen copies. */
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

    public const S2B = [
        'workspaces' => ['name' => 'varchar(80) NOT NULL', 'code' => 'varchar(20) NULL', 'term' => 'varchar(40) NULL'],
        'modules' => ['title' => 'varchar(120) NOT NULL', 'about' => 'varchar(300) NULL'],
        'folders' => ['name' => 'varchar(120) NOT NULL'],
        'files' => ['name' => 'varchar(200) NOT NULL'],
        'topics' => ['name' => 'varchar(120) NOT NULL'],
        'links' => ['title' => 'varchar(200) NOT NULL', 'url' => 'varchar(2000) NOT NULL'],
        'instructions' => ['text' => 'varchar(2000) NOT NULL'],
        'activities' => ['title' => 'varchar(200) NOT NULL'],
        'activity_items' => ['title' => 'varchar(200) NOT NULL', 'notes' => 'text NULL', 'labels' => 'json NULL'],
        'activity_members' => ['name' => 'varchar(80) NOT NULL'],
        'activity_sources' => ['title' => 'varchar(200) NULL', 'text' => 'longtext NOT NULL'],
        'activity_understandings' => ['body' => 'json NOT NULL', 'changes' => 'text NULL', 'sources' => 'json NOT NULL'],
        'activity_log' => ['text' => 'varchar(500) NOT NULL'],
        'course_profiles' => ['about' => 'text NULL', 'outcomes' => 'json NULL', 'assessment' => 'json NULL', 'textbook' => 'varchar(200) NULL', 'syllabus_text' => 'mediumtext NULL', 'proposed_modules' => 'json NULL', 'language' => 'varchar(40) NULL', 'tools' => 'json NULL', 'exam_format' => 'varchar(300) NULL'],
        'learner_profiles' => ['preferences' => 'json NULL', 'note' => 'text NULL'],
        'engine_settings' => ['language' => 'varchar(40) NULL'],
        'file_digests' => ['summary' => 'text NULL', 'outline' => 'json NULL', 'topics' => 'json NULL', 'language' => 'varchar(30) NULL'],
        'topic_suggestions' => ['name' => 'varchar(120) NOT NULL', 'reason' => 'varchar(300) NULL', 'note' => 'varchar(500) NULL', 'covers' => 'text NULL', 'sources' => 'text NULL'],
        'module_briefs' => ['text' => 'mediumtext NOT NULL'],
        'folder_briefs' => ['text' => 'mediumtext NOT NULL'],
        'quizzes' => ['questions' => 'json NOT NULL'],
    ];

    public static function all(): array
    {
        return self::S2A + self::S2B;
    }

    public static function fields(string $table): array
    {
        return array_keys(self::all()[$table] ?? []);
    }

    /** Physical primary keys, within one learner. */
    public static function primary(string $table): array
    {
        return match ($table) {
            'journal_content' => ['entry_id', 'field'],
            'projection_snapshots' => ['rules_version'],
            'module_briefs' => ['module_id'],
            'folder_briefs' => ['folder_id'],
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
