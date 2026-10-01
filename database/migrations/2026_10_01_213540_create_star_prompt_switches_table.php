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
        Schema::create('star_prompt_switches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('star_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->index()->constrained()->cascadeOnDelete();
            $table->string('prompt_name', 128);
            $table->boolean('enabled');
            $table->timestamps();

            $table->unique(['star_id', 'connection_id', 'prompt_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('star_prompt_switches');
    }
};
