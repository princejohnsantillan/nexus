<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * When the reconciliation last asked PayMongo about a pending payment, so
 * each run takes the ones it asked about longest ago (or never) first, and
 * payments PayMongo keeps failing on can't keep the others waiting.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->timestamp('reconciled_at')->nullable();
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex(['status', 'created_at']);
            $table->dropColumn('reconciled_at');
        });
    }
};
