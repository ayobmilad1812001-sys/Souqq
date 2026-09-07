<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            // Orders are financial records: a user row may not disappear from
            // under them.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->enum('status', OrderStatus::values())->default(OrderStatus::Pending->value);

            // Money columns. Totals are recomputed from line items at creation
            // and then frozen, so a later price change never rewrites history.
            $table->decimal('subtotal', 10, 2);
            $table->decimal('shipping_cost', 10, 2);
            $table->decimal('total', 10, 2);

            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            // "My orders, newest first" and admin status boards.
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            // Products are never hard-deleted while orders reference them, so
            // historical invoices stay resolvable.
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('quantity');
            // Price captured at purchase time -- deliberately denormalised so a
            // seller repricing tomorrow cannot alter yesterdays invoice.
            $table->decimal('unit_price', 10, 2);
            $table->decimal('subtotal', 10, 2);
            $table->timestamps();

            $table->index('order_id');
            // Sellers query their own sales across every order.
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
