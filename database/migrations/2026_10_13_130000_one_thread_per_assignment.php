<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An assignment has one companion chat: two first messages at once could each start a thread, and the second one's
 * turns would be lost from the chat. Threads of no assignment (sessions, topics, ViStud AI) have a null activity_id,
 * which a unique index lets repeat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engine_threads', function (Blueprint $table) {
            $table->dropIndex(['learner_id', 'activity_id']);
            $table->unique(['learner_id', 'activity_id']);
        });
    }

    public function down(): void
    {
        Schema::table('engine_threads', function (Blueprint $table) {
            $table->dropUnique(['learner_id', 'activity_id']);
            $table->index(['learner_id', 'activity_id']);
        });
    }
};
