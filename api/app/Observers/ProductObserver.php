<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Product;
use App\Services\CatalogCache;

/**
 * Cache invalidation hook (PRD 4.5).
 *
 * Attaching invalidation to the model rather than to the service guarantees it
 * runs for *every* write path -- a seller update, an admin edit, a stock
 * decrement inside checkout, or a future artisan command -- so no code path can
 * leave a stale price or stock figure in Redis.
 */
final readonly class ProductObserver
{
    public function __construct(private CatalogCache $cache) {}

    public function saved(Product $product): void
    {
        $this->cache->forgetProduct($product->id);
    }

    public function deleted(Product $product): void
    {
        $this->cache->forgetProduct($product->id);
    }
}
