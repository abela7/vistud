<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workspaces: a student's space for one subject (M2 step 1,
 * docs/specs/workspaces.md). A learner table: reached only through
 * App\Platform\Database\LearnerTables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->string('name', 80);
            $table->string('code', 20)->nullable();
            $table->string('term', 40)->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            // One of App\Appearance\Theme::CATEGORIES.
            $table->string('colour', 16);
            // One of App\Study\Workspaces::ICONS.
            $table->string('icon', 32);
            // "general" for every workspace until workspace types arrive.
            $table->string('type', 32)->default('general');
            $table->unsignedInteger('position');
            // Bumped on every change, matching the journal record's revision.
            $table->unsignedInteger('revision')->default(1);
            $table->dateTime('archived_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->index(['learner_id', 'archived_at', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};
