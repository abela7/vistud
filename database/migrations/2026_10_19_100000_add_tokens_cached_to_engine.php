<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many of a call's tokens in the service read from its prompt cache (billed at about a tenth), beside `tokens_in`,
 * so Usage this month can say how much of what was sent was cached.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['engine_messages', 'engine_jobs'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->unsignedInteger('tokens_cached')->default(0)->after('tokens_in');
            });
        }
    }

    public function down(): void
    {
        foreach (['engine_messages', 'engine_jobs'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('tokens_cached');
            });
        }
    }
};
