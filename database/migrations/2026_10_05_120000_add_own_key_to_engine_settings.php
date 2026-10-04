<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A student's own key for the engine's service (the owner's ask, 2026-10-05: a student sets the chat up
 * themselves, no admin needed), kept encrypted with the app key beside their other engine settings. With one,
 * their chats go on their own account at the service; without one, the key an admin set up for everyone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engine_settings', function (Blueprint $table) {
            $table->text('key_encrypted')->nullable()->after('consented_at');
            $table->dateTime('key_updated_at', 6)->nullable()->after('key_encrypted');
        });
    }

    public function down(): void
    {
        Schema::table('engine_settings', function (Blueprint $table) {
            $table->dropColumn(['key_encrypted', 'key_updated_at']);
        });
    }
};
