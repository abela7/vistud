<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Projects (the owner's review, 2026-10-03): an assignment's plan grows the tools of a small project manager, for
 * a big assignment or a group one. A part or a step gets dates (`start_on`, `due_on`), a `priority`, `notes`,
 * `labels` (a JSON list of short words), a person it is for (`member_id`, one of the assignment's team) and when
 * it was finished (`done_at`, for the burndown to come). A step can sit under a step, three levels deep.
 * `state` now also holds doing and stuck for parts and steps, and pending or achieved for a new kind of item, the
 * milestone (a date to reach). `activity_members` is the team of a group assignment: names only, no accounts,
 * since a student's data stays their own; `me` marks the student's own name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_items', function (Blueprint $table) {
            $table->date('start_on')->nullable()->after('weight');
            $table->date('due_on')->nullable()->after('start_on');
            $table->string('priority', 6)->nullable()->after('due_on');
            $table->text('notes')->nullable()->after('priority');
            $table->json('labels')->nullable()->after('notes');
            $table->uuid('member_id')->nullable()->after('labels');
            $table->dateTime('done_at', 6)->nullable()->after('state');
        });

        Schema::create('activity_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->uuid('workspace_id');
            $table->uuid('activity_id');
            $table->string('name', 80);
            $table->boolean('me')->default(false);
            $table->unsignedInteger('position');
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->index(['learner_id', 'activity_id']);
            $table->index(['learner_id', 'workspace_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_members');
        Schema::table('activity_items', function (Blueprint $table) {
            $table->dropColumn(['start_on', 'due_on', 'priority', 'notes', 'labels', 'member_id', 'done_at']);
        });
    }
};
