<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modules and folders inside a workspace (M2 step 2, docs/specs/workspaces.md).
 * Both are learner tables, reached only through LearnerTables.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A workspace's units, in order. Each change is also a revision of
        // the `module` journal record (ADR 0002).
        Schema::create('modules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->uuid('workspace_id');
            $table->string('title', 120);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->unsignedInteger('position');
            $table->unsignedInteger('revision')->default(1);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->index(['learner_id', 'workspace_id', 'position']);
        });

        // Organisation only, never in the journal (ADR 0003 §9.2). A folder
        // sits in a module (parent_id null) or in another folder, up to 8
        // levels deep; module_id is the module at the top of its branch.
        Schema::create('folders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->uuid('workspace_id');
            $table->uuid('module_id');
            $table->uuid('parent_id')->nullable();
            $table->string('name', 120);
            $table->unsignedTinyInteger('depth');
            $table->unsignedInteger('position');
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->index(['learner_id', 'workspace_id']);
            $table->index(['learner_id', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folders');
        Schema::dropIfExists('modules');
    }
};
