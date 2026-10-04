<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The language a student wants to be taught in (the owner's ask, 2026-10-05: set it once instead of asking every
 * time). Empty: the tutor answers in the language the student writes in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engine_settings', function (Blueprint $table) {
            $table->string('language', 40)->nullable()->after('no_training');
        });
    }

    public function down(): void
    {
        Schema::table('engine_settings', function (Blueprint $table) {
            $table->dropColumn('language');
        });
    }
};
