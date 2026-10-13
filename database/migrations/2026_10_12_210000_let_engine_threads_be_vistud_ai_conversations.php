<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ViStud AI's conversations (App\Engine\VistudAi): threads above every course, with no session, topic or course. A
 * thread says what it is for (`purpose`: null for a session's or a topic's chat, `vistud` for ViStud AI's) and, for
 * the list of conversations, a short title from its first words. The student keeps as many as they like; each folds
 * its oldest turns into a summary as it grows, as a session's chat does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engine_threads', function (Blueprint $table) {
            // ViStud AI's are in no course.
            $table->uuid('workspace_id')->nullable()->change();
            $table->string('purpose', 10)->nullable()->after('topic_id');
            $table->string('title', 120)->nullable()->after('purpose');
            $table->index(['learner_id', 'purpose', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('engine_threads', function (Blueprint $table) {
            $table->dropIndex(['learner_id', 'purpose', 'updated_at']);
            $table->dropColumn(['purpose', 'title']);
        });
    }
};
