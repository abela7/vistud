<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A note is created when the student first writes in it, not when they
 * press New note (the owner's review, 2026-09-27). The editor sends an ID
 * of its own with that first save, `create_id`, so a retry after a lost
 * answer finds the note it already made instead of making a second one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->string('create_id', 64)->nullable()->after('folder_id');
            $table->unique(['learner_id', 'create_id']);
        });
    }

    public function down(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->dropUnique(['learner_id', 'create_id']);
            $table->dropColumn('create_id');
        });
    }
};
