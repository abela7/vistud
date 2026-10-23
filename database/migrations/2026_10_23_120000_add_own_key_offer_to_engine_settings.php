<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ViStud AI's question to a student on the shared key: would they like their own AI key (the owner's ask,
 * 2026-10-23)? Their answer (yes, no or later) and when, so "no" is never asked again and "later" in a few days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engine_settings', function (Blueprint $table) {
            $table->string('own_key_offer', 8)->nullable()->after('dictation_language');
            $table->dateTime('own_key_offer_at', 6)->nullable()->after('own_key_offer');
        });
    }

    public function down(): void
    {
        Schema::table('engine_settings', function (Blueprint $table) {
            $table->dropColumn(['own_key_offer', 'own_key_offer_at']);
        });
    }
};
