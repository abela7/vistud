<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A suggested topic says why (the owner's ask, 2026-10-12): the AI reads all of a part's files first, leaves out what
 * isn't study material, and suggests only main topics, each with why it matters, the smaller ideas it covers, where in
 * the files it is taught, and its place in the order to study them (App\Engine\Jobs\ChooseTopics). A suggestion made
 * before this has none of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topic_suggestions', function (Blueprint $table) {
            $table->string('reason', 300)->nullable()->after('name_key');
            // JSON: the smaller ideas it holds (list of strings), and where it is taught ([{file, pages}]).
            $table->text('covers')->nullable()->after('reason');
            $table->text('sources')->nullable()->after('covers');
            $table->unsignedSmallInteger('position')->nullable()->after('sources');
        });
    }

    public function down(): void
    {
        Schema::table('topic_suggestions', function (Blueprint $table) {
            $table->dropColumn(['reason', 'covers', 'sources', 'position']);
        });
    }
};
