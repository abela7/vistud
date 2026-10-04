<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every run of the reader or the helper (docs/specs/vistud-2-blueprint.md §3.6.3): reading a file, writing a
 * session's summary, folding a long chat, a quick edit. One row per run, with what it cost, so the month's limit
 * counts it and the settings page can show the month by role; the tutor's chat keeps its own cost on its
 * messages. A learner table, reached only through LearnerTables. It carries ids and codes, never the student's
 * words or the model's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engine_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->uuid('workspace_id')->nullable();
            // reader or helper: whose model did it.
            $table->string('role', 10);
            // read_file, profile_course, brief_module, note_from_file, cards_from, wrap_up, fold, quick.
            $table->string('kind', 40);
            // What it worked on (a file, a session, a module), when it worked on one.
            $table->string('target_type', 30)->nullable();
            $table->string('target_id', 64)->nullable();
            // queued, running, done, failed or skipped.
            $table->string('status', 10)->default('queued');
            $table->string('model', 120)->nullable();
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            // Millionths of a dollar.
            $table->unsignedBigInteger('cost_micros')->default(0);
            // Why it failed or was skipped: a code, never words.
            $table->string('error_code', 60)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTime('started_at', 6)->nullable();
            $table->dateTime('finished_at', 6)->nullable();
            $table->dateTime('created_at', 6);

            $table->index(['learner_id', 'created_at']);
            $table->index(['learner_id', 'target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engine_jobs');
    }
};
