<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Questions as the student works through them (docs/specs/study-memory.md
 * §3): pending, stuck or answered, the module they belong to, the answer
 * once there is one, and the study session they were asked in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->uuid('module_id')->nullable()->after('topic_id');
            // pending, stuck or answered: the student's word.
            $table->string('status', 9)->default('pending')->after('text');
            $table->text('answer')->nullable()->after('status');
            $table->uuid('session_id')->nullable()->after('answer');
            $table->dateTime('answered_at', 6)->nullable()->after('session_id');

            $table->index(['learner_id', 'workspace_id', 'module_id']);
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropIndex(['learner_id', 'workspace_id', 'module_id']);
            $table->dropColumn(['module_id', 'status', 'answer', 'session_id', 'answered_at']);
        });
    }
};
