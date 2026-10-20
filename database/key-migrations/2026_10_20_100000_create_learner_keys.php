<?php

use App\Platform\Database\LearnerTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'mysql_keys_owner';

    public function up(): void
    {
        LearnerTables::assertKeyDatabase();
        Schema::connection($this->connection)->create('learner_keys', function (Blueprint $table) {
            $table->uuid('learner_id')->primary();
            $table->unsignedSmallInteger('key_version');
            $table->char('master_version', 64);
            $table->text('wrapped_key');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('learner_keys');
    }
};
