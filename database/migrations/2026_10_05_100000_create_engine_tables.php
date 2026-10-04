<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The built-in chat's engine (docs/specs/study-memory.md §6): each student's choice of models and limits, and
 * the chat of each study session (its turns, what each cost, and the summary its oldest turns are folded
 * into). Learner tables, reached only through LearnerTables. The chat is kept to be read again and to save
 * from; the memory stays the journal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engine_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('learner_id')->unique();
            // Model ids as the service names them ("anthropic/claude-sonnet-4.5"); null until chosen.
            $table->string('tutor_model', 120)->nullable();
            $table->string('quick_model', 120)->nullable();
            $table->string('fallback_model', 120)->nullable();
            // Millionths of a dollar.
            $table->unsignedBigInteger('session_cap_micros');
            $table->unsignedBigInteger('month_cap_micros');
            $table->boolean('no_training')->default(true);
            $table->dateTime('consented_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
        });

        Schema::create('engine_threads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->uuid('workspace_id');
            $table->uuid('session_id');
            $table->string('model', 120);
            // The oldest turns, folded into a few lines, and the position they are folded through.
            $table->text('summary')->nullable();
            $table->unsignedInteger('folded_through')->default(0);
            $table->unsignedBigInteger('spent_micros')->default(0);
            $table->unsignedInteger('turns')->default(0);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->unique(['learner_id', 'session_id']);
            $table->index(['learner_id', 'workspace_id']);
        });

        Schema::create('engine_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->uuid('thread_id');
            $table->unsignedInteger('position');
            // user, assistant or tool.
            $table->string('role', 10);
            $table->mediumText('content')->nullable();
            $table->json('tool_calls')->nullable();
            $table->string('tool_call_id', 64)->nullable();
            $table->string('tool_name', 60)->nullable();
            $table->string('model', 120)->nullable();
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->unsignedBigInteger('cost_micros')->default(0);
            $table->dateTime('created_at', 6);

            $table->unique(['thread_id', 'position']);
            $table->index(['learner_id', 'thread_id']);
            $table->index(['learner_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engine_messages');
        Schema::dropIfExists('engine_threads');
        Schema::dropIfExists('engine_settings');
    }
};
