<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A module says what it is (the owner's ask, 2026-10-12): a module is any group studied together (a week, a chapter, a
 * unit, a lab block, a project phase), and a line of what it is about tells the student and the tutor which. Its parts
 * are its folders, and a folder may say what kind of part it is (a lecture, a lab, reading: App\Study\ModuleParts), so a
 * lab week reads as one. Both optional: a module or folder made before this, or by hand, has neither.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modules', function (Blueprint $table) {
            $table->string('about', 300)->nullable()->after('title');
        });
        Schema::table('folders', function (Blueprint $table) {
            $table->string('kind', 20)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('modules', function (Blueprint $table) {
            $table->dropColumn('about');
        });
        Schema::table('folders', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
