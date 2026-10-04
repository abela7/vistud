<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The session as a conversation the tutor keeps the course for (docs/specs/vistud-2-blueprint.md §3.5.4, §3.7, §3.9).
 * A study session has a mode (the whole module, one topic, a quiz, a test, or free), so the tutor and the screens
 * know what it is for. A topic's status says who set it and when, so the student's word wins over the tutor's and the
 * tutor's can be undone. A quiz or a test is kept as a readable record: what was asked, how it went, the score (the
 * attempts themselves stay in the journal). Additive; what is there is backfilled from what it has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_sessions', function (Blueprint $table) {
            // module, topic, quiz, test or free.
            $table->string('mode', 10)->default('topic')->after('topic_id');
        });
        // A session with a module and no topic was the whole module; one with neither was free.
        DB::table('study_sessions')->whereNull('topic_id')->whereNotNull('module_id')->update(['mode' => 'module']);
        DB::table('study_sessions')->whereNull('topic_id')->whereNull('module_id')->update(['mode' => 'free']);

        Schema::table('topics', function (Blueprint $table) {
            // student or tutor: who set the status the row shows now.
            $table->string('status_by', 10)->nullable()->after('status');
            $table->dateTime('status_at', 6)->nullable()->after('status_by');
        });
        // Every status there is was the student's word.
        DB::table('topics')->whereNotNull('status')->update(['status_by' => 'student', 'status_at' => DB::raw('updated_at')]);

        Schema::create('quizzes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->uuid('workspace_id');
            $table->uuid('session_id')->nullable();
            $table->uuid('module_id')->nullable();
            $table->uuid('topic_id')->nullable();
            // quiz (about five questions on a topic) or test (ten to fifteen over a module).
            $table->string('kind', 10);
            // [{asked, answer, result, right, fix, topic}]
            $table->json('questions');
            // 0 to 100: a correct answer counts one, a partial half.
            $table->unsignedTinyInteger('score')->default(0);
            $table->unsignedSmallInteger('asked')->default(0);
            $table->dateTime('started_at', 6);
            $table->dateTime('finished_at', 6);

            $table->index(['learner_id', 'session_id']);
            $table->index(['learner_id', 'module_id', 'finished_at']);
            $table->index(['learner_id', 'workspace_id', 'finished_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quizzes');
        Schema::table('topics', function (Blueprint $table) {
            $table->dropColumn(['status_by', 'status_at']);
        });
        Schema::table('study_sessions', function (Blueprint $table) {
            $table->dropColumn('mode');
        });
    }
};
