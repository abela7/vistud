<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Study sessions and their clock (docs/specs/study-memory.md §4). A session
 * is made of segments, each a stretch of study or of a break, so time is
 * counted from what happened, not from one start and one end. Learner
 * tables, reached only through LearnerTables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('study_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->uuid('workspace_id');
            $table->uuid('module_id')->nullable();
            $table->uuid('topic_id')->nullable();
            // running, paused, break or ended.
            $table->string('state', 8);
            // Why it's paused: student, away (no activity) or long_break.
            $table->string('paused_by', 12)->nullable();
            // Logged afterwards, without the clock.
            $table->boolean('manual')->default(false);
            $table->dateTime('started_at', 6);
            $table->dateTime('ended_at', 6)->nullable();
            $table->dateTime('last_activity_at', 6);
            // The closed segments' totals; an open segment is added when read.
            $table->unsignedInteger('study_seconds')->default(0);
            $table->unsignedInteger('break_seconds')->default(0);
            $table->unsignedInteger('revision');
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->index(['learner_id', 'workspace_id', 'started_at']);
            $table->index(['learner_id', 'state']);
        });

        Schema::create('session_segments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->uuid('session_id');
            // study or break.
            $table->string('kind', 8);
            $table->dateTime('started_at', 6);
            $table->dateTime('ended_at', 6)->nullable();
            // pause, break, resume, end, away, long_break or log.
            $table->string('ended_by', 12)->nullable();

            $table->index(['learner_id', 'session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_segments');
        Schema::dropIfExists('study_sessions');
    }
};
