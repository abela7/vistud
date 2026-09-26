<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uploaded files (M2 step 4, docs/specs/workspaces.md). A learner table,
 * reached only through LearnerTables. The bytes live on the private files
 * disk under storage_key, never under the uploaded name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->uuid('workspace_id');
            $table->uuid('module_id')->nullable();
            $table->uuid('folder_id')->nullable();
            // The name shown, without its extension; the extension is checked against the bytes.
            $table->string('name', 200);
            $table->string('extension', 8);
            $table->string('kind', 16);
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            // A fingerprint of the bytes: for spotting copies, and for readers (search, AI) to cache what they read.
            $table->char('sha256', 64);
            $table->string('storage_key', 200);
            $table->unsignedInteger('position');
            $table->dateTime('trashed_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->index(['learner_id', 'workspace_id']);
            $table->index(['learner_id', 'folder_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
