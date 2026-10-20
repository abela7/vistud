<?php

use App\Platform\Database\ContentColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (ContentColumns::S2A as $table => $fields) {
            $clauses = ['ADD `content_version` TINYINT UNSIGNED NOT NULL DEFAULT 0'];
            if (in_array('content_identity', ContentColumns::identity($table), true)) {
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
        foreach (ContentColumns::S2A as $table => $fields) {
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
        foreach (ContentColumns::S2A as $table => $fields) {
            $clauses = ['DROP `content_version`'];
            if (in_array('content_identity', ContentColumns::identity($table), true)) {
                $clauses[] = 'DROP `content_identity`';
            }
            foreach ($fields as $field => $original) {
                $clauses[] = "MODIFY `{$field}` {$original}";
            }
            DB::statement('ALTER TABLE `'.$table.'` '.implode(', ', $clauses));
        }
    }
};
