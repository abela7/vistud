<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rest of the tracker (docs/specs/study-memory.md §3, step 1b): findings
 * pinned to topics, web links kept beside notes and files, assignments and
 * tasks, and the instructions a study session starts from. Learner tables,
 * reached only through LearnerTables.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Short "must know" lines, pinned to a topic, with where they came from.
        Schema::create('findings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->uuid('workspace_id');
            $table->uuid('topic_id');
            $table->string('text', 500);
            // A note or a file of the workspace, and where in it ("slide 12", "p. 4").
            $table->string('source_type', 8)->nullable();
            $table->uuid('source_id')->nullable();
            $table->string('locator', 60)->nullable();
            // student, or ai when a study session wrote it.
            $table->string('author', 8);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->index(['learner_id', 'workspace_id']);
        });

        // Web links, in the same places as notes and files.
        Schema::create('links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->uuid('workspace_id');
            $table->uuid('module_id')->nullable();
            $table->uuid('folder_id')->nullable();
            $table->string('title', 200);
            $table->string('url', 2000);
            $table->unsignedInteger('position');
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->index(['learner_id', 'workspace_id']);
            $table->index(['learner_id', 'folder_id']);
        });

        // Assignments, quizzes, exams and tasks; each is also an `activity` record in the journal.
        Schema::create('activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->uuid('workspace_id');
            $table->uuid('module_id')->nullable();
            $table->string('kind', 16);
            $table->string('title', 200);
            $table->date('due_on')->nullable();
            // todo, doing or done.
            $table->string('status', 8);
            $table->unsignedInteger('revision');
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->index(['learner_id', 'workspace_id']);
        });

        // How the assistant should work with the student: `me` (every course), `workspace:{id}` or `module:{id}`.
        Schema::create('instructions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->string('scope', 64);
            $table->string('text', 2000);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->unique(['learner_id', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructions');
        Schema::dropIfExists('activities');
        Schema::dropIfExists('links');
        Schema::dropIfExists('findings');
    }
};
