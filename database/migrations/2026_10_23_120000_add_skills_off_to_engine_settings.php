<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The skills a student switched off for themselves in AI settings (App\Engine\Skills\Skills, the owner's ask,
 * 2026-10-23): a JSON list of names; null when none is off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engine_settings', function (Blueprint $table) {
            $table->json('skills_off')->nullable()->after('shared_models_off');
        });
    }

    public function down(): void
    {
        Schema::table('engine_settings', function (Blueprint $table) {
            $table->dropColumn('skills_off');
        });
    }
};
