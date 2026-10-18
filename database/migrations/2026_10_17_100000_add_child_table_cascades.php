<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pure child tables follow their parent row when it is deleted (docs/specs/mcp-connections-plan.md §4 M6.0).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('engine_messages')->whereNotIn('thread_id', DB::table('engine_threads')->select('id'))->delete();
        DB::table('session_segments')->whereNotIn('session_id', DB::table('study_sessions')->select('id'))->delete();
        DB::table('note_versions')->whereNotIn('note_id', DB::table('notes')->select('id'))->delete();

        Schema::table('engine_messages', function (Blueprint $table) {
            $table->foreign('thread_id')->references('id')->on('engine_threads')->cascadeOnDelete();
        });

        Schema::table('session_segments', function (Blueprint $table) {
            $table->foreign('session_id')->references('id')->on('study_sessions')->cascadeOnDelete();
        });

        Schema::table('note_versions', function (Blueprint $table) {
            $table->foreign('note_id')->references('id')->on('notes')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('note_versions', function (Blueprint $table) {
            $table->dropForeign(['note_id']);
        });

        Schema::table('session_segments', function (Blueprint $table) {
            $table->dropForeign(['session_id']);
        });

        Schema::table('engine_messages', function (Blueprint $table) {
            $table->dropForeign(['thread_id']);
        });
    }
};
