<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notes, their versions and deletion records (M2 step 3,
 * docs/specs/workspaces.md, ADR 0003 §5 and §9.1). All learner tables,
 * reached only through LearnerTables. Folders may now also sit at a
 * workspace's top level, outside every module.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            $table->uuid('module_id')->nullable()->change();
        });

        // A note lives in a workspace, at its top level, in a module or in a
        // folder. module_id and folder_id say where; both null is the top level.
        Schema::create('notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->uuid('workspace_id');
            $table->uuid('module_id')->nullable();
            $table->uuid('folder_id')->nullable();
            $table->string('title', 200);
            $table->unsignedInteger('current_version');
            $table->unsignedInteger('position');
            $table->dateTime('trashed_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->index(['learner_id', 'workspace_id']);
            $table->index(['learner_id', 'folder_id']);
        });

        // Every accepted save is a new version; a version never changes, so
        // evidence can cite it (ADR 0003 §9.1). save_id makes retries safe.
        Schema::create('note_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->uuid('note_id');
            $table->unsignedInteger('version');
            $table->string('title', 200);
            $table->longText('doc');
            $table->unsignedInteger('base_version')->nullable();
            $table->string('save_id', 64)->nullable();
            $table->string('client_id', 64)->nullable();
            $table->string('kind', 24);
            $table->dateTime('created_at', 6);

            $table->unique(['note_id', 'version']);
            $table->unique(['note_id', 'save_id']);
            $table->index(['learner_id', 'note_id']);
        });

        // Durable deletion records, so browsers holding drafts learn what was
        // trashed, restored or deleted (ADR 0003 §5.4). The id is the cursor.
        Schema::create('content_tombstones', function (Blueprint $table) {
            $table->id();
            $table->uuid('learner_id');
            $table->string('entity_type', 24);
            $table->uuid('entity_id');
            $table->string('kind', 16);
            $table->dateTime('at', 6);

            $table->index(['learner_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_tombstones');
        Schema::dropIfExists('note_versions');
        Schema::dropIfExists('notes');
        Schema::table('folders', function (Blueprint $table) {
            $table->uuid('module_id')->nullable(false)->change();
        });
    }
};
