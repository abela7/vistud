<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const COLUMNS = [
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

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $fields) {
            $clauses = ['ADD `content_version` TINYINT UNSIGNED NOT NULL DEFAULT 0'];
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
                throw new RuntimeException('Decrypt and verify remaining content under maintenance before rollback.');
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
            foreach ($fields as $field => $original) {
                $clauses[] = "MODIFY `{$field}` {$original}";
            }
            DB::statement('ALTER TABLE `'.$table.'` '.implode(', ', $clauses));
        }
    }
};
