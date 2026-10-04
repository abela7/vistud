<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tutor acts in the course (the owner's ask, 2026-10-05: cards made straight away, notes taken together):
 * what each of its tools did is kept with the tool's message (`effects`: what was saved, which notes it wrote
 * in), and the session's own study note, the one the tutor writes in unless told another, is kept on the chat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engine_messages', function (Blueprint $table) {
            $table->json('effects')->nullable()->after('tool_name');
        });
        Schema::table('engine_threads', function (Blueprint $table) {
            $table->uuid('note_id')->nullable()->after('session_id');
        });
    }

    public function down(): void
    {
        Schema::table('engine_messages', function (Blueprint $table) {
            $table->dropColumn('effects');
        });
        Schema::table('engine_threads', function (Blueprint $table) {
            $table->dropColumn('note_id');
        });
    }
};
