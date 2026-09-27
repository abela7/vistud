<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One open study session per student, kept by the database (the owner's
 * review, 2026-09-27): `open_learner` is the student's ID while a session
 * is open and NULL once it has ended, and it is unique. Two starts at once,
 * from two tabs, can't both succeed.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A student somehow left with two open sessions keeps the newest; the older ones end where they last moved.
        $open = DB::table('study_sessions')->where('state', '!=', 'ended')->orderByDesc('started_at')->get(['id', 'learner_id', 'last_activity_at']);
        $kept = [];
        foreach ($open as $row) {
            if (isset($kept[$row->learner_id])) {
                DB::table('study_sessions')->where('id', $row->id)->update(['state' => 'ended', 'paused_by' => null, 'ended_at' => $row->last_activity_at]);
                DB::table('session_segments')->where('session_id', $row->id)->whereNull('ended_at')->update(['ended_at' => $row->last_activity_at, 'ended_by' => 'end']);
            }
            $kept[$row->learner_id] = true;
        }

        Schema::table('study_sessions', function (Blueprint $table) {
            $table->uuid('open_learner')->nullable()->storedAs("if(`state` <> 'ended', `learner_id`, null)")->after('state');
            $table->unique('open_learner');
        });
    }

    public function down(): void
    {
        Schema::table('study_sessions', function (Blueprint $table) {
            $table->dropUnique(['open_learner']);
            $table->dropColumn('open_learner');
        });
    }
};
