<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The model that turns a student's voice into words (App\Engine\Dictation, the owner's ask, 2026-10-23). Null: the
 * default, Whisper Large V3 Turbo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engine_settings', function (Blueprint $table) {
            $table->string('dictation_model', 80)->nullable()->after('language');
        });
    }

    public function down(): void
    {
        Schema::table('engine_settings', function (Blueprint $table) {
            $table->dropColumn('dictation_model');
        });
    }
};
