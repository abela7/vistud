<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A model's reasoning, as the service hands it back (`reasoning_details`): some models (Claude) require it to
 * be sent back unchanged with the message it belongs to when that message asked for a look-up, or they refuse
 * the next request. Kept with the assistant's message and sent back to the same model only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engine_messages', function (Blueprint $table) {
            $table->json('reasoning')->nullable()->after('tool_calls');
        });
    }

    public function down(): void
    {
        Schema::table('engine_messages', function (Blueprint $table) {
            $table->dropColumn('reasoning');
        });
    }
};
