<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A transcribed class keeps its two notes: the word-for-word transcript and the lecture notes made from it
 * (App\Engine\Jobs\TranscribeClass). Transcribing again writes new versions of the same two notes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->uuid('transcript_note_id')->nullable()->after('note_id');
            $table->uuid('lecture_notes_id')->nullable()->after('transcript_note_id');
        });
    }

    public function down(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->dropColumn(['transcript_note_id', 'lecture_notes_id']);
        });
    }
};
