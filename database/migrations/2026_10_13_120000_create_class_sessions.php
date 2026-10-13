<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Class sessions (the owner's ask, 2026-10-13): a class the student was in (a lecture, a tutorial, a lab, a seminar),
 * recorded, turned into a transcript by a speech-to-text system of the owner's choosing, and reviewed by the AI with the
 * slides the teacher used, so the tutor can say what the teacher said. See docs/specs/class-sessions.md.
 *
 * - `class_sessions`: the class: its module (and folder, when it was one of the module's sittings), kind, title, day,
 *   teacher, language, how it stands (`status`), whether the audio is kept once transcribed (`keep_audio`), when the
 *   student said they may record it (`consent_at`), and its length.
 * - `class_recordings`: its audio, in parts (one part per continuous recording), each kept on the files disk and filled
 *   chunk by chunk as it is recorded (`chunks` counts what has arrived, so a chunk sent twice is kept once). `path` is
 *   null once the audio is deleted; the row stays, to say there was one.
 * - `class_transcripts`: what was said, as text and as timed segments, one row per revision: from the speech-to-text
 *   system (`source` = transcriber) or given by the student (`source` = student).
 * - `class_materials`: the files the teacher used that day (slides, handouts), which stay where they are.
 * - `class_reviews`: what the AI made of a transcript and its slides, one row per revision, every point with the time
 *   in the class it was said.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->uuid('workspace_id');
            // Null only when its folder was moved out of every module since.
            $table->uuid('module_id')->nullable();
            $table->uuid('folder_id')->nullable();
            $table->string('kind', 10);
            $table->string('title', 200);
            $table->date('held_on');
            $table->string('teacher', 120)->nullable();
            $table->string('language', 40)->nullable();
            $table->string('status', 12);
            $table->boolean('keep_audio')->default(true);
            $table->dateTime('consent_at', 6)->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->string('problem', 200)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->index(['learner_id', 'module_id', 'held_on']);
            $table->index(['learner_id', 'workspace_id']);
        });

        Schema::create('class_recordings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->uuid('workspace_id');
            $table->uuid('class_session_id');
            $table->unsignedSmallInteger('position');
            $table->string('mime', 60);
            $table->string('extension', 8);
            $table->string('path', 255)->nullable();
            $table->unsignedBigInteger('bytes')->default(0);
            $table->unsignedInteger('chunks')->default(0);
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->char('sha256', 64)->nullable();
            $table->dateTime('finished_at', 6)->nullable();
            $table->dateTime('deleted_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->index(['learner_id', 'class_session_id']);
            $table->index(['learner_id', 'workspace_id']);
        });

        Schema::create('class_transcripts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->uuid('workspace_id');
            $table->uuid('class_session_id');
            $table->unsignedInteger('revision');
            $table->longText('text');
            $table->json('segments');
            $table->string('language', 40)->nullable();
            $table->string('source', 12);
            $table->string('provider', 120)->nullable();
            $table->unsignedInteger('word_count')->default(0);
            $table->dateTime('created_at', 6);

            $table->unique(['learner_id', 'class_session_id', 'revision']);
            $table->index(['learner_id', 'workspace_id']);
        });

        Schema::create('class_materials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->uuid('workspace_id');
            $table->uuid('class_session_id');
            $table->uuid('file_id');
            $table->string('role', 10);
            $table->dateTime('created_at', 6);

            $table->unique(['learner_id', 'class_session_id', 'file_id']);
            $table->index(['learner_id', 'file_id']);
        });

        Schema::create('class_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->uuid('workspace_id');
            $table->uuid('class_session_id');
            $table->unsignedInteger('revision');
            $table->unsignedInteger('transcript_revision');
            $table->json('body');
            $table->string('model', 120)->nullable();
            $table->dateTime('created_at', 6);

            $table->unique(['learner_id', 'class_session_id', 'revision']);
            $table->index(['learner_id', 'workspace_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_reviews');
        Schema::dropIfExists('class_materials');
        Schema::dropIfExists('class_transcripts');
        Schema::dropIfExists('class_recordings');
        Schema::dropIfExists('class_sessions');
    }
};
