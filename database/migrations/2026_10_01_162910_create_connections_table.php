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
        Schema::create('connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('connector_key', 64)->nullable();
            $table->string('name', 100);
            $table->string('handle', 24);
            $table->string('description', 200)->nullable();
            $table->string('account_identity', 100)->nullable();
            $table->string('url', 2048);
            $table->string('auth_type', 16);
            $table->string('status', 16)->default('pending');
            $table->json('settings')->nullable();
            $table->text('secrets')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamp('catalog_refreshed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'handle']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('connections');
    }
};
