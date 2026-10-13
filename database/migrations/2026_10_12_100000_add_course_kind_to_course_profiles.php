<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What else every AI should know about a course (the owner's ask, 2026-10-12: "the AI understanding starts from here"),
 * kept apart from what the student is like in it (learner_profiles) and from how they learn (the journal): its level and
 * the language it is taught in (fixed lists, App\Study\CourseTypes), its tools or notation (a few short tags: Python,
 * SQL, R), how its exams go (a line), the subject plugins every new session starts with (null: those of its type), and
 * which of the guide's questions the student skipped, so no AI asks them again. The type itself is the course's own
 * column, workspaces.type, kept for this since M2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_profiles', function (Blueprint $table) {
            $table->string('level', 20)->nullable()->after('textbook');
            $table->string('language', 40)->nullable()->after('level');
            $table->json('tools')->nullable()->after('language');
            $table->string('exam_format', 300)->nullable()->after('tools');
            $table->json('plugins')->nullable()->after('exam_format');
            $table->json('skipped')->nullable()->after('plugins');
        });
    }

    public function down(): void
    {
        Schema::table('course_profiles', function (Blueprint $table) {
            $table->dropColumn(['level', 'language', 'tools', 'exam_format', 'plugins', 'skipped']);
        });
    }
};
