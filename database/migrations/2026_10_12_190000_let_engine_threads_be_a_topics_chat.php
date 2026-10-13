<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A chat on a topic's own page (the owner's ask, 2026-10-12): the same tutor chat as a study session's, with no session
 * (App\Engine\SessionChat::sendOnTopic). Its thread belongs to the topic instead: session_id is null and topic_id says
 * which topic. Its messages are the tutor's, so they count in the month's use as the session chats do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engine_threads', function (Blueprint $table) {
            $table->uuid('session_id')->nullable()->change();
            $table->uuid('topic_id')->nullable()->after('session_id');
            $table->index(['learner_id', 'topic_id']);
        });
    }

    public function down(): void
    {
        Schema::table('engine_threads', function (Blueprint $table) {
            $table->dropIndex(['learner_id', 'topic_id']);
            $table->dropColumn('topic_id');
        });
    }
};
