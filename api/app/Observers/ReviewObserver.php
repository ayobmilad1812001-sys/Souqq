<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Review;
use App\Services\CatalogCache;

/**
 * A new review changes the products cached average rating and review count.
 */
final readonly class ReviewObserver
{
    public function __construct(private CatalogCache $cache) {}

    public function saved(Review $review): void
    {
        $this->cache->forgetProduct($review->product_id);
    }

    public function deleted(Review $review): void
    {
        $this->cache->forgetProduct($review->product_id);
    }
}
