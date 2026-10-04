<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the tutor asks before adding or switching topics (the owner's ask, 2026-10-06: topics should keep
 * themselves). Off by default: the tutor keeps them as it teaches and says so in a line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engine_settings', function (Blueprint $table) {
            $table->boolean('ask_topics')->default(false)->after('language');
        });
    }

    public function down(): void
    {
        Schema::table('engine_settings', function (Blueprint $table) {
            $table->dropColumn('ask_topics');
        });
    }
};
