<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reviewing flashcards (docs/specs/study-memory.md §4.5): when each card is
 * next due, how far up the ladder of gaps it is, and what the last answer
 * was. Every answer is also an attempt in the journal; these columns only
 * say when to ask again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flashcards', function (Blueprint $table) {
            // The card's content revision, as its journal task has it (ADR 0002 §5).
            $table->unsignedSmallInteger('revision')->default(1)->after('back');
            // The rung on App\Study\Flashcards::LADDER; 0 is a new or missed card.
            $table->unsignedTinyInteger('step')->default(0)->after('session_id');
            // The student's date it's next due; null is a new card, due now.
            $table->date('due_on')->nullable()->after('step');
            $table->unsignedInteger('reviews')->default(0)->after('due_on');
            $table->unsignedInteger('lapses')->default(0)->after('reviews');
            // correct, partial or incorrect.
            $table->string('last_result', 9)->nullable()->after('lapses');
            $table->dateTime('last_reviewed_at', 6)->nullable()->after('last_result');

            $table->index(['learner_id', 'workspace_id', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::table('flashcards', function (Blueprint $table) {
            $table->dropIndex(['learner_id', 'workspace_id', 'due_on']);
            $table->dropColumn(['revision', 'step', 'due_on', 'reviews', 'lapses', 'last_result', 'last_reviewed_at']);
        });
    }
};
