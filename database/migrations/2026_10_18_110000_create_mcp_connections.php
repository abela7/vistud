<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A chat app the student let into ViStud over MCP (docs/specs/mcp-connections-plan.md §3.4, M6.1).
 *
 * - `mcp_connections`: one row per student and OAuth client, written when the student approves it on ViStud's consent
 *   page. Without a live row, a token is refused (fail closed). `provider` is fixed at consent from the client's
 *   redirect host; `client_label` is what the client called itself and is never trusted. `level` is read, write or full.
 * - `mcp_calls`: what each call did, without its text (the Connections screen, a session's saved rail, Undo). M6.2 on.
 * - `mcp_confirmations`: a change the student must confirm on a ViStud page before it runs. M6.5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->uuid('oauth_client_id');
            $table->string('kind', 8);
            $table->string('provider', 16);
            $table->string('client_label', 128)->nullable();
            $table->string('redirect_host', 255)->nullable();
            $table->string('level', 8);
            $table->dateTime('consented_at', 6);
            $table->dateTime('revoked_at', 6)->nullable();
            $table->string('revoked_reason', 24)->nullable();
            $table->dateTime('last_used_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->unique(['learner_id', 'oauth_client_id']);
            $table->index(['learner_id', 'revoked_at']);
        });

        Schema::create('mcp_calls', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->uuid('connection_id');
            $table->string('tool', 60);
            $table->string('outcome', 24);
            $table->string('target_type', 24)->nullable();
            $table->string('target_id', 64)->nullable();
            $table->unsignedInteger('chars')->default(0);
            $table->json('effects')->nullable();
            $table->dateTime('created_at', 6);

            $table->index(['learner_id', 'created_at']);
            $table->index(['learner_id', 'connection_id', 'created_at']);
            $table->foreign('connection_id')->references('id')->on('mcp_connections')->cascadeOnDelete();
        });

        Schema::create('mcp_confirmations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->uuid('connection_id');
            $table->string('tool', 60);
            $table->string('target_id', 64)->nullable();
            $table->json('input');
            $table->char('input_hash', 64);
            $table->dateTime('expires_at', 6);
            $table->dateTime('used_at', 6)->nullable();
            $table->dateTime('created_at', 6);

            $table->index(['learner_id', 'connection_id']);
            $table->foreign('connection_id')->references('id')->on('mcp_connections')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_confirmations');
        Schema::dropIfExists('mcp_calls');
        Schema::dropIfExists('mcp_connections');
    }
};
