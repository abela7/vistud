<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Pomodoro clock of a study session (docs/specs/study-memory.md §4.2):
 * its settings, the phase it is in, and how many focus periods were done.
 * The phases are counted from the session's segments, like all its time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_sessions', function (Blueprint $table) {
            // {focus, short, long, every, auto} in minutes; null is the free clock.
            $table->json('pomodoro')->nullable()->after('manual');
            // focus, short_break or long_break.
            $table->string('phase', 12)->nullable()->after('pomodoro');
            $table->dateTime('phase_started_at', 6)->nullable()->after('phase');
            $table->unsignedInteger('pomodoros')->default(0)->after('phase_started_at');
            $table->unsignedInteger('pomodoros_skipped')->default(0)->after('pomodoros');
        });
    }

    public function down(): void
    {
        Schema::table('study_sessions', function (Blueprint $table) {
            $table->dropColumn(['pomodoro', 'phase', 'phase_started_at', 'pomodoros', 'pomodoros_skipped']);
        });
    }
};
