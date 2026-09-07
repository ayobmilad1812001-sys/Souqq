<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();

            // A seller owning zero products may not be deleted while products
            // exist; restrict forces the platform to reassign or remove them
            // first rather than silently orphaning catalogue rows.
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();

            $table->string('name');
            $table->text('description');

            // DECIMAL, never FLOAT/DOUBLE: binary floating point cannot store
            // most two-decimal values exactly and the error compounds across
            // line items. 10,2 covers up to 99,999,999.99 per unit.
            $table->decimal('price', 10, 2);

            $table->string('sku')->unique();
            $table->unsignedInteger('stock_quantity')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Catalogue browsing is category + active filtered and price sorted;
            // this composite serves the common listing query with one index.
            $table->index(['category_id', 'is_active', 'price']);
            // Seller dashboards list a single sellers products, newest first.
            $table->index(['seller_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
