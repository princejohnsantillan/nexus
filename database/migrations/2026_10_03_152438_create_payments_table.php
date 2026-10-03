<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row for each checkout a user starts on PayMongo for a month or a year
 * of Pro: pending until PayMongo says it was paid or it expired. A paid one
 * records how it was paid and the Pro period it bought.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->ulid('reference')->unique();
            $table->string('period', 8);
            $table->unsignedInteger('amount');
            $table->string('currency', 3);
            $table->string('status', 16);
            $table->string('checkout_session_id')->nullable()->unique();
            $table->string('checkout_url')->nullable();
            $table->string('paymongo_payment_id')->nullable();
            $table->string('method', 32)->nullable();
            $table->string('card_brand', 32)->nullable();
            $table->string('card_last4', 4)->nullable();
            $table->string('receipt_email')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('pro_from')->nullable();
            $table->timestamp('pro_until')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
