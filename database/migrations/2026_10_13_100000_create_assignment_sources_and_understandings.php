<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The assignment companion's first step, the full picture (the owner's ask, 2026-10-13): everything the student is
 * given about an assignment, and what the AI understands from it, kept so every model reads the same thing.
 *
 * - `activity_sources`: what the student gives besides the files in the assignment's folder: the brief's text, the
 *   marking criteria, an email, an announcement, what the teacher said, their own note, or a correction to the AI's
 *   understanding. `kind` says which; `received_on` when it came. The text keeps its lines.
 * - `activity_understandings`: the AI's understanding of the assignment, one row per revision, never changed after:
 *   `body` (its goal, deliverables, requirements, criteria, what the teacher wants to see, dates, the modules and
 *   topics it draws on, assumptions, risks, gaps and the questions to ask the teacher, each with the source it came
 *   from), `changes` (what changed from the revision before, and why), `sources` (the labels it cited, S1 or F2, and
 *   what each was), `fingerprint` (of the sources it read: a different one now means it is out of date), `model` (who
 *   wrote it), and `confirmed_at` (when the student said it is right).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->uuid('workspace_id');
            $table->uuid('activity_id');
            $table->string('kind', 12);
            $table->string('title', 200)->nullable();
            $table->longText('text');
            $table->date('received_on')->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->index(['learner_id', 'activity_id']);
            $table->index(['learner_id', 'workspace_id']);
        });

        Schema::create('activity_understandings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->uuid('workspace_id');
            $table->uuid('activity_id');
            $table->unsignedInteger('revision');
            $table->json('body');
            $table->text('changes')->nullable();
            $table->json('sources');
            $table->char('fingerprint', 40);
            $table->string('model', 120)->nullable();
            $table->dateTime('confirmed_at', 6)->nullable();
            $table->dateTime('created_at', 6);

            $table->unique(['learner_id', 'activity_id', 'revision']);
            $table->index(['learner_id', 'workspace_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_understandings');
        Schema::dropIfExists('activity_sources');
    }
};
