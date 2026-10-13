<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the session's topic became its topic (the owner's ask, 2026-10-12): studying a topic is contact with it, so when the
 * session moves on to another topic or ends, the time on the one it leaves is written to the journal as an exposure
 * (App\Study\Sessions::contact), and the topic's evidence moves on from "no contact yet". Null with no topic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_sessions', function (Blueprint $table) {
            $table->dateTime('topic_since', 6)->nullable()->after('topic_id');
        });
    }

    public function down(): void
    {
        Schema::table('study_sessions', function (Blueprint $table) {
            $table->dropColumn('topic_since');
        });
    }
};
