<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the AI knows about a course and about how its student likes to learn (docs/specs/vistud-2-blueprint.md §3.5.2,
 * §3.7). The course profile is what the course is (about it, what it should teach, how it is assessed, its textbook),
 * written by the student or read by the reader from a syllabus, with the module list the reader found until the
 * student has ticked what to add. The learner profile is the student's own answers for this course: how they like
 * things explained, their pace, how often to be checked, their goal. Both are learner tables, one row per course,
 * created when first needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->uuid('workspace_id');
            $table->text('about')->nullable();
            // A list of short lines.
            $table->json('outcomes')->nullable();
            // [{name, kind, weight, due_on}]
            $table->json('assessment')->nullable();
            $table->string('textbook', 200)->nullable();
            // The syllabus to read: the file the student gave (kept with the course) or the text they pasted, which
            // waits here until the reader has read it and is then cleared. Neither goes in a queue's payload.
            $table->uuid('syllabus_file_id')->nullable();
            $table->mediumText('syllabus_text')->nullable();
            // [{title, starts_on, ends_on}]: found by the reader, waiting for the student to tick them.
            $table->json('proposed_modules')->nullable();
            // manual or file (a pasted text counts as manual: nothing was uploaded).
            $table->string('source', 10)->default('manual');
            $table->string('model', 120)->nullable();
            $table->dateTime('built_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->unique(['learner_id', 'workspace_id']);
        });

        Schema::create('learner_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->uuid('workspace_id');
            // {explain: [..], pace, check, goal}
            $table->json('preferences')->nullable();
            $table->text('note')->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->unique(['learner_id', 'workspace_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learner_profiles');
        Schema::dropIfExists('course_profiles');
    }
};
