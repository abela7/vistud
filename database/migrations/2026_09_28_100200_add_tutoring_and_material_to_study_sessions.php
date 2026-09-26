<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How the assistant should teach in a session, and the material chosen for
 * it (docs/specs/study-memory.md §4.3). Both go into the session's briefing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_sessions', function (Blueprint $table) {
            // {method, check_ins, quiz, pace}: App\Study\Tutoring.
            $table->json('tutoring')->nullable()->after('pomodoros_skipped');
            // ["note:{id}", "file:{id}"]: notes and files of the workspace.
            $table->json('material')->nullable()->after('tutoring');
        });
    }

    public function down(): void
    {
        Schema::table('study_sessions', function (Blueprint $table) {
            $table->dropColumn(['tutoring', 'material']);
        });
    }
};
