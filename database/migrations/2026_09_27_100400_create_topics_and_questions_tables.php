<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tracker's organisation tables (docs/specs/study-memory.md §3). Each
 * row's id is the journal entity's id: the topic or question itself is a
 * `defines` claim, and its state is derived from the journal. These tables
 * hold only where things sit and what the student last clicked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('topics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->uuid('workspace_id');
            $table->uuid('module_id')->nullable();
            $table->string('name', 120);
            // The student's latest word: covered, understood or confused; null is not started.
            $table->string('status', 16)->nullable();
            $table->unsignedInteger('position');
            $table->dateTime('retired_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->index(['learner_id', 'workspace_id']);
        });

        Schema::create('questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->uuid('workspace_id');
            $table->uuid('topic_id')->nullable();
            $table->string('text', 1000);
            // The journal event of the ask, which an answer responds to.
            $table->uuid('ask_event_id');
            $table->boolean('ask_teacher')->default(false);
            $table->dateTime('retired_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->index(['learner_id', 'workspace_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questions');
        Schema::dropIfExists('topics');
    }
};
