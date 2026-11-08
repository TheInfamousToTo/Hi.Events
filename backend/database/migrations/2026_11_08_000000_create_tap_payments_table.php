<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tap_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('charge_id')->unique();
            // Exact decimal string sent to Tap (e.g. "12.500" for BHD), compared
            // verbatim against what Tap reports before an order is completed.
            $table->string('amount');
            $table->string('currency', 3);
            $table->string('status')->default('INITIATED');
            $table->string('refund_id')->nullable();
            $table->jsonb('last_response')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tap_payments');
    }
};
