<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No foreign keys: recovery must survive the account and key disappearing.
        Schema::create('erasure_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->unique();
            $table->uuid('learner_ref')->nullable()->index();
            $table->timestamp('requested_at');
            $table->timestamp('not_before')->index();
            $table->string('stage', 24)->default('pending');
            $table->string('error_code', 48)->nullable();
            $table->timestamp('completed_at')->nullable();
        });
        Schema::create('erasure_log', function (Blueprint $table) {
            $table->uuid('request_id')->primary();
            $table->uuid('user_id')->unique();
            $table->uuid('learner_ref')->nullable()->index();
            $table->timestamp('requested_at');
            $table->timestamp('erased_at');
            $table->unsignedSmallInteger('version')->default(1);
        });
    }

    public function down(): void
    {
        // Dropping these on a live installation would remove restore protection.
        foreach (['erasure_requests', 'erasure_log'] as $name) {
            if (Schema::hasTable($name) && DB::table($name)->exists()) {
                throw new RuntimeException('Preserve erasure records before rolling back this schema.');
            }
        }
        Schema::dropIfExists('erasure_log');
        Schema::dropIfExists('erasure_requests');
    }
};
