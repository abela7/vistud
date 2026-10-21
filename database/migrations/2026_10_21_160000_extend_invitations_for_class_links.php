<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('kind', 10)->default('email');
            $table->unsignedInteger('places')->nullable();
            $table->unsignedInteger('uses')->default(0);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->char('privacy_version', 64)->nullable();
            $table->dateTime('privacy_accepted_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('invitations')->where('kind', 'class')->exists() || DB::table('users')->whereNotNull('privacy_accepted_at')->exists()) {
            throw new RuntimeException('Preserve class invitations and privacy acceptance before rollback.');
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['privacy_version', 'privacy_accepted_at']));
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropColumn(['kind', 'places', 'uses']);
            $table->string('email')->nullable(false)->change();
        });
    }
};
