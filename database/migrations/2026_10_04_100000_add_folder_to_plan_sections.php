<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A section of an assignment's plan keeps its own things (the owner's review, 2026-10-04): `folder_id` is the
 * section's folder, inside the assignment's own folder, where the notes, files and folders added to the section
 * are kept (App\Study\Plans::folder). Made the first time something is added, renamed with the section, removed
 * with it when empty and kept, with what is in it, when not. So the plan and the files follow one structure:
 * workspace, module, the assignment's folder, a folder for each section.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_items', function (Blueprint $table) {
            $table->uuid('folder_id')->nullable()->after('parent_id');
        });
    }

    public function down(): void
    {
        Schema::table('activity_items', function (Blueprint $table) {
            $table->dropColumn('folder_id');
        });
    }
};
