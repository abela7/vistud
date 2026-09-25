<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Redaction, clean-up and canonical files (ADR 0002 §10, ADR 0001 "Source
 * files"). Draft: docs/architecture/schema.md. The owner of work package
 * WP5 may revise these through the contract-change process.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Block entries: from the moment a redaction commits, nothing may
        // serve the blocked event or source (ADR 0002 §10 step 1.4).
        Schema::create('journal_blocks', function (Blueprint $table) {
            $table->uuid('learner_id');
            // event · source
            $table->string('entity_type', 16);
            $table->string('entity_id', 64);
            $table->uuid('redaction_id');
            $table->dateTime('created_at', 6);

            $table->primary(['learner_id', 'entity_type', 'entity_id']);
        });

        // One row per redaction. Mirrors the redaction ledger, which lives
        // outside MySQL. Never holds the redacted text.
        Schema::create('redactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('learner_id')->index();
            $table->string('amendment_id', 64);
            $table->json('targets');
            $table->json('fields')->nullable();
            $table->string('reason', 32);
            $table->unsignedBigInteger('ledger_seq')->unique();
            $table->dateTime('created_at', 6);
            $table->dateTime('cleanup_completed_at', 6)->nullable();
            $table->dateTime('cleanup_overdue_at', 6)->nullable();
        });

        // Transactional outbox: to-do records written in the same transaction
        // as the change that needs them. Payloads hold IDs only, never text.
        Schema::create('outbox', function (Blueprint $table) {
            $table->id();
            $table->uuid('learner_id')->nullable()->index();
            $table->string('task', 64);
            $table->json('payload');
            // pending · done
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTime('next_attempt_at', 6);
            // An error class or code. Never content.
            $table->string('last_error', 255)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('completed_at', 6)->nullable();

            $table->index(['status', 'next_attempt_at']);
        });

        // Canonical source files, stored per learner (ADR 0001 day-one rule 6).
        Schema::create('canonical_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Null only for shared course material.
            $table->uuid('learner_id')->nullable()->index();
            // private · shared
            $table->string('visibility', 8);
            $table->string('storage_key', 255)->unique();
            $table->char('sha256', 64);
            $table->unsignedBigInteger('size');
            $table->string('media_type', 128);
            $table->dateTime('created_at', 6);
            $table->dateTime('deleted_at', 6)->nullable();
        });

        // Small named markers, such as the last redaction-ledger entry applied.
        Schema::create('system_markers', function (Blueprint $table) {
            $table->string('name', 64)->primary();
            $table->string('value', 255);
            $table->dateTime('updated_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_markers');
        Schema::dropIfExists('canonical_files');
        Schema::dropIfExists('outbox');
        Schema::dropIfExists('redactions');
        Schema::dropIfExists('journal_blocks');
    }
};
