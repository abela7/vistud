<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An assignment's plan (the owner's review, 2026-10-02): one list for every kind of assignment, made of parts
 * (the sections or deliverables), steps (small things to do, under a part or on their own) and criteria (what it
 * is marked on, to check oneself against). `weight` is the marks a part or a criterion is worth, as a percentage;
 * null when not given. `state` is todo or done for a step or a part, not_yet, partly or met for a criterion.
 * `position` orders the items of one kind under one parent. A study aid, not evidence: its changes are not
 * journal records (App\Study\Plans).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->uuid('workspace_id');
            $table->uuid('activity_id');
            $table->string('kind', 10);
            $table->uuid('parent_id')->nullable();
            $table->string('title', 200);
            $table->unsignedTinyInteger('weight')->nullable();
            $table->string('state', 8);
            $table->unsignedInteger('position');
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->index(['learner_id', 'activity_id']);
            $table->index(['learner_id', 'workspace_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_items');
    }
};
