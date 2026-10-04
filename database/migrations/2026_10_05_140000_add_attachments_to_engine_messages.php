<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The notes and files a student attaches to a message in the chat (the owner's ask, 2026-10-05: material in the
 * chat): each one's reference, name and kind, and the text that went with it to the engine (App\Engine\Attachments).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engine_messages', function (Blueprint $table) {
            $table->json('attachments')->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('engine_messages', function (Blueprint $table) {
            $table->dropColumn('attachments');
        });
    }
};
