<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whose a student's own key is (the owner's ask, 2026-10-12): OpenRouter, one key for every model, or a provider's own
 * (OpenAI, Anthropic, Google and the others in App\Engine\Providers), found when the key is saved. A key kept before
 * this is OpenRouter's (null).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engine_settings', function (Blueprint $table) {
            $table->string('key_provider', 24)->nullable()->after('key_encrypted');
        });
    }

    public function down(): void
    {
        Schema::table('engine_settings', function (Blueprint $table) {
            $table->dropColumn('key_provider');
        });
    }
};
