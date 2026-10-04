<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings of the installation itself, set by an admin on a screen (docs/specs/study-memory.md §6): the AI
 * engine's key (kept encrypted with the app key), its service address and the default models. Not a learner
 * table: nothing in it belongs to one student. The runtime user reads and writes it; the audit log says who
 * changed what.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            // Encrypted when `secret`; never shown whole again.
            $table->text('value')->nullable();
            $table->boolean('secret')->default(false);
            $table->uuid('updated_by')->nullable();
            $table->dateTime('updated_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
