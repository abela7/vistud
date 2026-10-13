<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who said a question is answered (the owner's ask, 2026-10-12): the student, or the AI that wrote its answer. Only the
 * student's word is a `clicked` self-report in the journal; an answer the AI wrote stays "not understood yet" until the
 * student says it makes sense (App\Study\Questions::setStatus). Answered ones before this were the student's word.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->string('answered_by', 8)->nullable()->after('answered_at');
        });
        DB::table('questions')->where('status', 'answered')->update(['answered_by' => 'student']);
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn('answered_by');
        });
    }
};
