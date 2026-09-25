<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cached derived state (ADR 0002 §1). Provisional: docs/architecture/schema.md.
 *
 * Derived state is never stored as truth. A snapshot is a cache of the
 * projector's output at a journal position, and can be deleted at any time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projection_snapshots', function (Blueprint $table) {
            $table->uuid('learner_id');
            $table->string('rules_version', 16);
            $table->unsignedBigInteger('position');
            // IDs, labels, states and flags only. Never content.
            $table->json('snapshot');
            $table->dateTime('computed_at', 6);

            $table->primary(['learner_id', 'rules_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projection_snapshots');
    }
};
