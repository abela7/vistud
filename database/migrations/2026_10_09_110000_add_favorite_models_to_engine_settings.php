<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engine_settings', function (Blueprint $table) {
            $table->json('favorite_models')->nullable()->after('fallback_model');
        });
    }

    public function down(): void
    {
        Schema::table('engine_settings', function (Blueprint $table) {
            $table->dropColumn('favorite_models');
        });
    }
};
