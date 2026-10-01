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
        Schema::create('star_oauth_clients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('star_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('client_id')->unique()->constrained('oauth_clients')->cascadeOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('star_oauth_clients');
    }
};
