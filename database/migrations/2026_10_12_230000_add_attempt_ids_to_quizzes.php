<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The journal entries a quiz wrote (its answers, as attempts), by id (the owner's ask, 2026-10-12): deleting a quiz
 * retracts exactly these, so its answers stop counting for the topics they were about
 * (App\Study\Quizzes::delete). Null for a quiz made before this: its attempts are found by session, question and time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->json('attempt_ids')->nullable()->after('questions');
        });
    }

    public function down(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropColumn('attempt_ids');
        });
    }
};
