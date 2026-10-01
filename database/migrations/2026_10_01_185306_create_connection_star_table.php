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
        Schema::create('connection_star', function (Blueprint $table): void {
            $table->foreignId('star_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->index()->constrained()->cascadeOnDelete();

            $table->primary(['star_id', 'connection_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('connection_star');
    }
};
