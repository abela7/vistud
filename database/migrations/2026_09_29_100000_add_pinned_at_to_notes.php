<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A pinned note has a button in the corner of every page (the owner's
 * review, 2026-09-29). `pinned_at` is when it was pinned, which is also
 * the order of the buttons; null is not pinned. A note in the trash is not
 * pinned (trashing clears it), and a student pins at most Notes::MAX_PINNED.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->timestamp('pinned_at')->nullable()->after('trashed_at');
            $table->index(['learner_id', 'pinned_at']);
        });
    }

    public function down(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->dropIndex(['learner_id', 'pinned_at']);
            $table->dropColumn('pinned_at');
        });
    }
};
