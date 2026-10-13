<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each time the session's topic changed, who changed it (the student or the tutor), to what and when (the owner's ask,
 * 2026-10-12): the chat shows where it changed, and the tutor is told where in the chat the student changed it
 * (App\Study\Sessions::topicLog). Null until it first changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_sessions', function (Blueprint $table) {
            $table->json('topic_log')->nullable()->after('topic_since');
        });
    }

    public function down(): void
    {
        Schema::table('study_sessions', function (Blueprint $table) {
            $table->dropColumn('topic_log');
        });
    }
};
