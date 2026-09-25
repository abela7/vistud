<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit log (ADR 0003 §10.4). Stable: docs/architecture/schema.md.
 *
 * The runtime database user gets SELECT and INSERT only on this table
 * (`php artisan vistud:db:grants`). It never holds email addresses or
 * learning content, and there are no foreign keys, so erasing an account
 * never touches it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->dateTime('occurred_at', 6);
            // user · system
            $table->string('actor_type', 16);
            $table->uuid('actor_user_id')->nullable();
            // The role the actor was acting in: student · admin · system
            $table->string('actor_role', 16);
            // Dotted name, for example role.granted or account.suspended.
            $table->string('action', 64);
            $table->string('target_type', 32)->nullable();
            $table->string('target_id', 64)->nullable();
            // Identifiers and flags only. Never content, never email addresses.
            $table->json('metadata')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('request_id', 64)->nullable();

            $table->index('occurred_at');
            $table->index(['target_type', 'target_id']);
            $table->index('actor_user_id');
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
