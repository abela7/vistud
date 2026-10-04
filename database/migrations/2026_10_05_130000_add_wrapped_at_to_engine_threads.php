<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a session ends, its chat is wrapped up once (the owner's ask, 2026-10-05: the next session must start
 * from what happened without the student explaining again): the quick model writes the session's summary and
 * checkpoint from the chat, and the thread remembers that it was done.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engine_threads', function (Blueprint $table) {
            $table->dateTime('wrapped_at', 6)->nullable()->after('turns');
        });
    }

    public function down(): void
    {
        Schema::table('engine_threads', function (Blueprint $table) {
            $table->dropColumn('wrapped_at');
        });
    }
};
