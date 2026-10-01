<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('provider_user_id');
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
        });

        // One data key per user. Only the wrapped (encrypted) form is stored;
        // deleting the row makes that user's stored credentials unreadable.
        Schema::create('data_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('driver');
            $table->text('wrapped_key');
            $table->timestamps();
        });

        Schema::create('connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('handle');
            $table->string('url', 2048);
            $table->string('auth_type');
            $table->json('settings')->nullable();
            $table->text('secrets')->nullable();
            $table->string('status');
            $table->string('status_message', 1000)->nullable();
            $table->string('protocol_version')->nullable();
            $table->timestamp('tools_refreshed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'handle']);
        });

        Schema::create('connection_tools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            // The tool definition exactly as the downstream server sent it, so
            // empty JSON objects ({}) survive the round trip through PHP.
            $table->longText('definition');
            $table->string('definition_hash', 64);
            $table->boolean('read_only')->default(false);
            $table->boolean('destructive')->default(false);
            $table->timestamps();

            $table->unique(['connection_id', 'name']);
        });

        Schema::create('vaults', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->ulid('public_id')->unique();
            $table->string('name');
            $table->string('description', 1000)->nullable();
            $table->timestamps();
        });

        Schema::create('connection_vault', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vault_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['vault_id', 'connection_id']);
        });

        // Explicit per-vault tool switches. A tool without a row falls back to
        // the default: on when the server marks it read-only, off otherwise.
        Schema::create('vault_tools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vault_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->string('tool_name');
            $table->boolean('enabled');
            $table->timestamps();

            $table->unique(['vault_id', 'connection_id', 'tool_name']);
        });

        Schema::create('vault_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vault_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('token_hash', 64)->unique();
            $table->string('hint', 32);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        // Metadata only. Tool arguments and results are never stored.
        Schema::create('tool_call_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vault_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vault_token_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tool_name');
            $table->string('status');
            $table->unsignedInteger('duration_ms');
            $table->unsignedInteger('response_bytes')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['vault_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_call_logs');
        Schema::dropIfExists('vault_tokens');
        Schema::dropIfExists('vault_tools');
        Schema::dropIfExists('connection_vault');
        Schema::dropIfExists('vaults');
        Schema::dropIfExists('connection_tools');
        Schema::dropIfExists('connections');
        Schema::dropIfExists('data_keys');
        Schema::dropIfExists('social_accounts');
    }
};
