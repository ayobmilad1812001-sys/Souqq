<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only ledger of every stock change. Written asynchronously by a
        // queued job so the hot order-creation transaction stays short, and it
        // gives operations a way to reconcile stock_quantity after an incident.
        Schema::create('inventory_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 40);
            // Negative for a sale, positive for a restock/cancellation.
            $table->integer('quantity_delta');
            $table->unsignedInteger('resulting_quantity');
            $table->timestamps();

            $table->index(['product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
