<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only learning journal (ADR 0002). Provisional:
 * docs/architecture/schema.md.
 *
 * - journal_entries holds the envelope and body of every event. Rows are
 *   never updated; corrections are new entries (amendments, reviews).
 * - journal_content holds free text, separately (ADR 0001 day-one rule 1).
 *   No SQL may filter, search or sort on it. Redaction deletes rows here.
 * - journal_mentions holds character ranges into content fields.
 * - journal_refs is a derived index of every reference an entry makes. It is
 *   written in the same transaction and can be rebuilt from the entries.
 *
 * Keys are (learner_id, id): IDs are unique within a learner stream, so a
 * clash with another learner's ID can never reveal that it exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->foreignUuid('learner_id')->constrained('learners')->restrictOnDelete();
            // UUIDv7 in production; the domain accepts any ID token (docs/architecture/conventions.md).
            $table->string('id', 64);
            $table->unsignedBigInteger('position');
            $table->string('kind', 16);
            $table->string('type', 64);
            $table->unsignedSmallInteger('type_version');
            $table->string('actor_type', 16);
            $table->string('actor_id', 64);
            $table->string('actor_channel', 8);
            $table->string('origin', 16)->nullable();
            // All times are UTC (the connection runs at +00:00).
            $table->dateTime('occurred_at', 6);
            $table->dateTime('occurred_until', 6)->nullable();
            $table->string('precision', 8);
            $table->string('tz', 64);
            $table->dateTime('interval_lo', 6);
            $table->dateTime('interval_hi', 6);
            $table->dateTime('recorded_at', 6);
            $table->dateTime('received_at', 6);
            $table->string('capture_key', 128)->nullable();
            $table->string('session_id', 64)->nullable();
            $table->string('activity_id', 64)->nullable();
            $table->json('links');
            $table->json('body');
            // Names of the content fields the entry was written with.
            $table->json('content_fields');
            // SHA-256 of the canonical specification, including content.
            // Same ID and same fingerprint is a duplicate; a different
            // fingerprint is a conflict.
            $table->char('fingerprint', 64);

            $table->primary(['learner_id', 'id']);
            $table->unique(['learner_id', 'position']);
            $table->unique(['learner_id', 'capture_key']);
            $table->index(['learner_id', 'kind', 'interval_lo']);
            $table->index(['learner_id', 'session_id']);
        });

        Schema::create('journal_content', function (Blueprint $table) {
            $table->uuid('learner_id');
            $table->string('entry_id', 64);
            $table->string('field', 32);
            $table->longText('text');

            $table->primary(['learner_id', 'entry_id', 'field']);
            $table->foreign(['learner_id', 'entry_id'])->references(['learner_id', 'id'])->on('journal_entries')->restrictOnDelete();
        });

        Schema::create('journal_mentions', function (Blueprint $table) {
            $table->uuid('learner_id');
            $table->string('entry_id', 64);
            $table->unsignedSmallInteger('n');
            $table->string('field', 32);
            $table->unsignedInteger('start');
            $table->unsignedInteger('end');

            $table->primary(['learner_id', 'entry_id', 'n']);
            $table->foreign(['learner_id', 'entry_id'])->references(['learner_id', 'id'])->on('journal_entries')->restrictOnDelete();
        });

        Schema::create('journal_refs', function (Blueprint $table) {
            $table->id();
            $table->uuid('learner_id');
            $table->string('entry_id', 64);
            // link:about · link:cites · link:responds_to · link:triggered_by ·
            // target · derived_from · supersedes · value
            $table->string('role', 24);
            $table->string('ref_type', 16);
            $table->string('ref_id', 64);
            $table->string('locator', 128)->nullable();

            $table->index(['learner_id', 'ref_type', 'ref_id']);
            $table->index(['learner_id', 'entry_id']);
            $table->foreign(['learner_id', 'entry_id'])->references(['learner_id', 'id'])->on('journal_entries')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_refs');
        Schema::dropIfExists('journal_mentions');
        Schema::dropIfExists('journal_content');
        Schema::dropIfExists('journal_entries');
    }
};
