<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Studying by folder (docs/specs/vistud-2-blueprint.md, Phase 9): a folder in a module is a place to study, so a session,
 * a topic, a question, a card, a quiz and a topic the reader found can belong to one, beside the module each has already
 * (a folder's things are its module's things too). Organisation only: like folders themselves, never in the journal
 * (ADR 0003 §9.2). A folder's layer of the tutor's context is kept as a module's is. Additive; nothing that is there
 * changes, so everything made before stays in its module, in no folder.
 */
return new class extends Migration
{
    private const TABLES = ['study_sessions', 'topics', 'questions', 'flashcards', 'quizzes', 'topic_suggestions'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->uuid('folder_id')->nullable()->after('module_id');
                $table->index(['learner_id', 'folder_id']);
            });
        }

        // What the tutor is told about a folder: as module_briefs, by a fingerprint of what it is made of.
        Schema::create('folder_briefs', function (Blueprint $table) {
            $table->uuid('folder_id')->primary();
            $table->uuid('learner_id');
            $table->mediumText('text');
            $table->char('fingerprint', 40);
            $table->dateTime('built_at', 6);
            $table->index('learner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folder_briefs');
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['learner_id', 'folder_id']);
                $table->dropColumn('folder_id');
            });
        }
    }
};
