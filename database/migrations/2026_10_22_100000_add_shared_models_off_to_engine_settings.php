<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which of the shared key's models a student switched off for the chat (the owner's ask, 2026-10-22). Kept as the
 * ones switched off, not on, so a model the admin adds later reaches every student at once. Null: none switched off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engine_settings', function (Blueprint $table) {
            $table->json('shared_models_off')->nullable()->after('favorite_models');
        });
    }

    public function down(): void
    {
        Schema::table('engine_settings', function (Blueprint $table) {
            $table->dropColumn('shared_models_off');
        });
    }
};
