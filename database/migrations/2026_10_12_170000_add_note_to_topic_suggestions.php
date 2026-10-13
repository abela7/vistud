<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The AI's note on a topic it chose (the owner's ask, 2026-10-12): why it chose it, from the material (how much of it
 * teaches it, where, whether the course's outcomes name it) and what it folded into it. Shown behind an (i) beside the
 * topic, apart from the line on why the topic matters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topic_suggestions', function (Blueprint $table) {
            $table->string('note', 500)->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('topic_suggestions', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
