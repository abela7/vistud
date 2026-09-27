<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The end-of-session write-back (docs/specs/study-memory.md §4.4): what a
 * session leaves behind for the next one (the tutor's summary and last
 * checkpoint, the marks already saved, the session note), and flashcards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_sessions', function (Blueprint $table) {
            // The tutor's summary for the next session, and where the session last stood.
            $table->text('summary')->nullable()->after('material');
            $table->text('checkpoint')->nullable()->after('summary');
            // Fingerprints of the marks already saved, so a second paste doesn't save them twice.
            $table->json('captured')->nullable()->after('checkpoint');
            // The session note the write-back keeps in the module.
            $table->uuid('note_id')->nullable()->after('captured');
        });

        // Flashcards: a front and a back, pinned to a topic. Review comes later.
        Schema::create('flashcards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->uuid('workspace_id');
            $table->uuid('topic_id')->nullable();
            $table->string('front', 500);
            $table->string('back', 1000);
            // student, or ai when a study session wrote it.
            $table->string('author', 8);
            $table->uuid('session_id')->nullable();
            $table->dateTime('retired_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->index(['learner_id', 'workspace_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flashcards');
        Schema::table('study_sessions', function (Blueprint $table) {
            $table->dropColumn(['summary', 'checkpoint', 'captured', 'note_id']);
        });
    }
};
