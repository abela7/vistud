<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Files go to the AI only when the student asks (the owner's ask, 2026-10-24): "Read my files automatically" is off by
 * default and turned off for everyone, since it spent tokens on every upload whether or not the student wanted it.
 * A student who wants it back turns it on in AI settings. The file page offers "Ask the AI" instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engine_settings', function (Blueprint $table) {
            $table->boolean('auto_read_files')->default(false)->change();
        });
        DB::table('engine_settings')->update(['auto_read_files' => false]);
    }

    public function down(): void
    {
        Schema::table('engine_settings', function (Blueprint $table) {
            $table->boolean('auto_read_files')->default(true)->change();
        });
    }
};
