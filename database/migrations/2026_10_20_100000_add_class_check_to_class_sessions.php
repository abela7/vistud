<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A class checked against its files: which of the module's files the lecture used (the student ticks them), and the
 * note the check wrote (App\Engine\Jobs\ClassCheck).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->json('check_files')->nullable()->after('lecture_notes_id');
            $table->uuid('check_note_id')->nullable()->after('check_files');
        });
    }

    public function down(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->dropColumn(['check_files', 'check_note_id']);
        });
    }
};
