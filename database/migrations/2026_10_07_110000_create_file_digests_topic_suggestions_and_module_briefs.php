<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the reader finds in a student's files and what the tutor is told about a module (docs/specs/
 * vistud-2-blueprint.md §3.5.3, §3.6.2, §3.7). A file's digest is what the reader wrote about it once (a short
 * summary, an outline, the topics it covers, its language), kept for the file's content as it is now: a changed file
 * is read again. A topic suggestion is a topic the reader found, waiting for the student to add or dismiss it. A
 * module's brief is the text of the tutor's module layer, kept by a fingerprint of what it was written from. All
 * three are learner tables; none holds the file itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_digests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->uuid('file_id');
            // The file's sha256 when it was read: the same bytes are never read twice.
            $table->char('content_hash', 64);
            // done, or skipped (a picture, or no words in it: `reason` says which).
            $table->string('status', 10);
            $table->string('reason', 30)->nullable();
            $table->text('summary')->nullable();
            // [{page, heading}]
            $table->json('outline')->nullable();
            // The topics it covers, up to eight names.
            $table->json('topics')->nullable();
            $table->string('language', 30)->nullable();
            $table->unsignedInteger('pages')->default(0);
            $table->unsignedInteger('chars')->default(0);
            $table->string('model', 120)->nullable();
            // Millionths of a dollar: what reading it cost.
            $table->unsignedBigInteger('cost_micros')->default(0);
            $table->dateTime('created_at', 6);

            $table->unique(['learner_id', 'file_id', 'content_hash']);
        });

        Schema::create('topic_suggestions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->uuid('module_id');
            $table->string('name', 120);
            // The name lower-cased: a module is suggested a topic once, whatever the file says.
            $table->string('name_key', 120);
            $table->uuid('source_file_id')->nullable();
            // suggested, added or dismissed.
            $table->string('status', 10)->default('suggested');
            $table->dateTime('created_at', 6);

            $table->unique(['learner_id', 'module_id', 'name_key']);
            $table->index(['learner_id', 'module_id', 'status']);
        });

        Schema::create('module_briefs', function (Blueprint $table) {
            $table->uuid('module_id')->primary();
            $table->uuid('learner_id');
            $table->mediumText('text');
            $table->char('fingerprint', 40);
            $table->dateTime('built_at', 6);

            $table->index('learner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_briefs');
        Schema::dropIfExists('topic_suggestions');
        Schema::dropIfExists('file_digests');
    }
};
