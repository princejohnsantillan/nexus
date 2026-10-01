<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('connection_prompts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->string('name', 128);
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->json('definition');
            $table->char('definition_hash', 64);
            $table->timestamps();

            $table->unique(['connection_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('connection_prompts');
    }
};
