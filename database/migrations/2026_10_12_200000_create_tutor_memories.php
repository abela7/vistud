<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the tutor remembers about its student (App\Study\TutorMemories): short lines it keeps from a chat, or the student
 * writes, so what they said about themselves ("scared of maths, needs the basics first") isn't lost when the chat ends.
 * For one course, or for every course (workspace_id null). The student sees, changes and deletes them; a switch in their
 * AI settings (tutor_remembers) lets the tutor keep them or not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tutor_memories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            // Null: for every course.
            $table->uuid('workspace_id')->nullable();
            $table->string('text', 200);
            // tutor or student: who wrote it.
            $table->string('by', 10);
            // The session (or the topic's chat) the tutor kept it in, when it did.
            $table->uuid('session_id')->nullable();
            $table->uuid('topic_id')->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->index(['learner_id', 'workspace_id']);
        });

        Schema::table('engine_settings', function (Blueprint $table) {
            $table->boolean('tutor_remembers')->default(true)->after('tutor_marks_topics');
        });
    }

    public function down(): void
    {
        Schema::table('engine_settings', function (Blueprint $table) {
            $table->dropColumn('tutor_remembers');
        });
        Schema::dropIfExists('tutor_memories');
    }
};
