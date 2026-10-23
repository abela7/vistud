<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The language a student speaks to voice typing, set beside the microphone (App\Engine\Dictation, the owner's ask,
 * 2026-10-23): an ISO-639-1 code, `auto` for the model to tell, or null to follow "Teach me in".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engine_settings', function (Blueprint $table) {
            $table->string('dictation_language', 8)->nullable()->after('dictation_model');
        });
    }

    public function down(): void
    {
        Schema::table('engine_settings', function (Blueprint $table) {
            $table->dropColumn('dictation_language');
        });
    }
};
