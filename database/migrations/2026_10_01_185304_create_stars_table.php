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
        Schema::create('stars', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('public_id', 20)->unique();
            $table->string('name', 100);
            $table->string('slug', 48);
            $table->string('description', 500)->nullable();
            $table->string('access_mode', 16)->default('token');
            $table->unsignedInteger('signed_url_version')->default(1);
            $table->string('new_tool_policy', 16)->default('read_only');
            $table->timestamps();

            $table->unique(['user_id', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stars');
    }
};
