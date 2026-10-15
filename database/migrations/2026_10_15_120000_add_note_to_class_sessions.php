<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A class has one note (the owner's ask, 2026-10-15). The note holds the
 * marks: each stretch of the recording, and what the student wrote under it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->uuid('note_id')->nullable()->after('problem');
            $table->index(['learner_id', 'note_id']);
        });
    }

    public function down(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->dropIndex(['learner_id', 'note_id']);
            $table->dropColumn('note_id');
        });
    }
};
