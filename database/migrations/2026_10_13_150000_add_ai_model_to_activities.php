<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The model the student chose to read and organise one assignment (the owner's ask, 2026-10-13). Null means the
 * tutor's model from their AI settings. Kept on the assignment, not on a screen, because the reading also starts
 * by itself when a source changes, and every start must use the same choice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->string('ai_model', 120)->nullable()->after('stage');
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn('ai_model');
        });
    }
};
