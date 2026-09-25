<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roles, learners and invitations (ADR 0003 §10.3). Stable:
 * docs/architecture/schema.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A user holds zero or more roles: student and/or admin. Revoking a
        // role deletes its row; the history is in audit_log.
        Schema::create('user_roles', function (Blueprint $table) {
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16);
            $table->timestamp('granted_at');
            // Null when the grant came from the console (a system action).
            $table->uuid('granted_by')->nullable();
            $table->primary(['user_id', 'role']);
            $table->index('role');
        });

        // The learner stream (ADR 0002 §3). Created with the student role.
        // Every private record carries learner_id (ADR 0001 day-one rule 2).
        Schema::create('learners', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained()->restrictOnDelete();
            // IANA time zone used to resolve day and week precision.
            $table->string('timezone', 64)->default('UTC');
            // Last journal position assigned. Positions are strictly increasing
            // per learner; the writer locks this row to assign the next one.
            $table->unsignedBigInteger('journal_position')->default(0);
            // Bumped on redaction so every cached brief or summary for this
            // learner becomes unreachable (ADR 0002 §10 step 5).
            $table->unsignedInteger('cache_version')->default(0);
            $table->timestamps();
        });

        // Invite-only accounts (D4). Accepting an invite creates a student
        // account; an invitation can never carry a role.
        Schema::create('invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email');
            // SHA-256 of the token. The token itself is shown once and never stored.
            $table->char('token_hash', 64)->unique();
            $table->uuid('invited_by')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->uuid('accepted_user_id')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
        Schema::dropIfExists('learners');
        Schema::dropIfExists('user_roles');
    }
};
