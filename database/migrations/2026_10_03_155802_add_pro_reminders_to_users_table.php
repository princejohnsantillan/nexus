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
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('pro_ends_soon_emailed_for')->nullable();
            $table->timestamp('pro_ended_emailed_for')->nullable();
            $table->index('pro_until');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['pro_until']);
            $table->dropColumn(['pro_ends_soon_emailed_for', 'pro_ended_emailed_for']);
        });
    }
};
