<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assignments with a deadline and their files (the owner's review, 2026-10-02). `due_time` is the time on the due
 * day ("23:59"), in the student's own time zone; null is the end of that day. `folder_id` is the assignment's own
 * folder, where its brief, its rubric and the student's drafts are kept (App\Study\Activities): made with the
 * assignment, in its module or at the workspace's top level, and kept when the assignment is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->time('due_time')->nullable()->after('due_on');
            $table->uuid('folder_id')->nullable()->after('module_id');
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn(['due_time', 'folder_id']);
        });
    }
};
