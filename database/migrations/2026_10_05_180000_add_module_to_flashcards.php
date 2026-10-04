<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flashcards belong to a module (the owner's ask, 2026-10-05: cards organised under each module), with or without
 * a topic: a card the tutor makes in a session on a course with no topics yet still has its place. Existing cards
 * take their topic's module, or else the module of the session they were made in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flashcards', function (Blueprint $table) {
            $table->uuid('module_id')->nullable()->after('topic_id');
            $table->index(['learner_id', 'workspace_id', 'module_id']);
        });
        $db = Schema::getConnection();
        $db->update('update flashcards set module_id = (select topics.module_id from topics where topics.id = flashcards.topic_id) where module_id is null and topic_id is not null');
        $db->update('update flashcards set module_id = (select study_sessions.module_id from study_sessions where study_sessions.id = flashcards.session_id) where module_id is null and session_id is not null');
    }

    public function down(): void
    {
        Schema::table('flashcards', function (Blueprint $table) {
            $table->dropIndex(['learner_id', 'workspace_id', 'module_id']);
            $table->dropColumn('module_id');
        });
    }
};
