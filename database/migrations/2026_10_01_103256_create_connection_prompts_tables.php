<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Prompts the downstream server advertised, cached from its last prompts/list.
        Schema::create('connection_prompts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->longText('definition');
            $table->string('definition_hash', 64);
            $table->timestamps();

            $table->unique(['connection_id', 'name']);
        });

        // Explicit per-vault prompt switches. Without one, a prompt is on:
        // prompts only produce instructions; the tools they lead to keep their own switches.
        Schema::create('vault_prompts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vault_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->string('prompt_name');
            $table->boolean('enabled');
            $table->timestamps();

            $table->unique(['vault_id', 'connection_id', 'prompt_name']);
        });

        Schema::table('tool_call_logs', function (Blueprint $table) {
            $table->string('kind')->default('tool')->after('vault_id');
        });
    }

    public function down(): void
    {
        Schema::table('tool_call_logs', function (Blueprint $table) {
            $table->dropColumn('kind');
        });

        Schema::dropIfExists('vault_prompts');
        Schema::dropIfExists('connection_prompts');
    }
};
