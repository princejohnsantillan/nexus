<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many tool calls each user's Stars forwarded in each billing week,
 * one row per user and week (the Monday it started on, in Philippine time),
 * for the weekly limit on Free and the Billing page's meter.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tool_call_counts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('week_starts_on')->index();
            $table->unsignedInteger('calls')->default(0);

            $table->unique(['user_id', 'week_starts_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tool_call_counts');
    }
};
