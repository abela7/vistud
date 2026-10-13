<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 2 of the assignment companion (the owner's ask, 2026-10-13): a dedicated companion chat thread,
 * a study stage (understand, plan, do, review, submit), and an activity log for decisions, progress,
 * advice, feedback and risks kept between the tutor and the student.
 *
 * - `engine_threads.activity_id`: one companion thread per assignment (`purpose = 'assignment'`).
 * - `activities.stage`: study progression stage (`understand` default, never written to journal).
 * - `activity_log`: durable entries recorded by tutor or student through the assignment's journey.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engine_threads', function (Blueprint $table) {
            $table->uuid('activity_id')->nullable()->after('topic_id');
            $table->index(['learner_id', 'activity_id']);
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->string('stage', 10)->default('understand')->after('status');
        });

        Schema::create('activity_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->uuid('workspace_id');
            $table->uuid('activity_id');
            $table->string('stage', 10);
            $table->string('kind', 12);
            $table->string('text', 500);
            $table->string('by', 10);
            $table->dateTime('created_at', 6);

            $table->index(['learner_id', 'activity_id', 'created_at']);
            $table->index(['learner_id', 'workspace_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');

        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn('stage');
        });

        Schema::table('engine_threads', function (Blueprint $table) {
            $table->dropIndex(['learner_id', 'activity_id']);
            $table->dropColumn('activity_id');
        });
    }
};
