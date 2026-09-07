<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\CacheKeys;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Read-through cache for the catalogue, plus the invalidation rules that keep
 * it honest (PRD 4.5).
 *
 * INVALIDATION STRATEGY
 * ---------------------
 * Two different shapes of cached data need two different tools:
 *
 * 1. Single-entity reads (a product, the category list) have one deterministic
 *    key, so a write simply forgets that key. Exact and immediate.
 *
 * 2. Product *listings* are cached per filter combination, which means one
 *    product edit can invalidate an unbounded number of keys. Redis has no
 *    efficient wildcard delete, and Cache::tags() is unavailable on the plain
 *    redis store, so listings live in a versioned namespace: every key embeds
 *    a counter, and a write increments the counter. All previously cached
 *    listings become unreachable in O(1) and expire on their own TTL.
 *
 * The trade-off of (2) is that a single product edit cold-starts every listing
 * query. That is the right way round for a marketplace: serving a sold-out or
 * mispriced product is far more expensive than recomputing a listing.
 */
final readonly class CatalogCache
{
    private function ttl(): int
    {
        return (int) config('marketplace.cache.ttl');
    }

    /** Current version of the product-listing namespace. */
    public function listingVersion(): int
    {
        // `remember` forever: the counter itself must never expire, or old
        // listing keys would become reachable again.
        return (int) Cache::rememberForever(CacheKeys::PRODUCT_INDEX_VERSION, static fn (): int => 1);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function rememberListing(array $filters, Closure $callback): mixed
    {
        return Cache::remember(
            CacheKeys::productIndex($filters, $this->listingVersion()),
            $this->ttl(),
            $callback
        );
    }

    public function rememberProduct(int $productId, Closure $callback): mixed
    {
        return Cache::remember(CacheKeys::product($productId), $this->ttl(), $callback);
    }

    public function rememberCategories(Closure $callback): mixed
    {
        return Cache::remember(CacheKeys::CATEGORY_LIST, $this->ttl(), $callback);
    }

    /**
     * Called whenever a product is created, updated or deleted.
     *
     * Both the entity key and the whole listing namespace are purged: a price
     * or stock change alters what listings should return, and a listing serving
     * a stale price is a customer-facing pricing error.
     */
    public function forgetProduct(int $productId): void
    {
        Cache::forget(CacheKeys::product($productId));
        Cache::forget(CacheKeys::productRating($productId));
        $this->bumpListingVersion();
    }

    /**
     * Category changes affect the cached category list and every product
     * listing (category name is embedded in the product payload, and the
     * ?category= filter resolves through it).
     */
    public function forgetCategories(): void
    {
        Cache::forget(CacheKeys::CATEGORY_LIST);
        $this->bumpListingVersion();
    }

    public function bumpListingVersion(): void
    {
        // Ensure the counter exists, then move it. Increment is atomic in
        // Redis, so concurrent product saves cannot land on the same version.
        $this->listingVersion();

        Cache::increment(CacheKeys::PRODUCT_INDEX_VERSION);
    }

    /** Full catalogue flush. Exposed for the maintenance artisan command. */
    public function flushAll(): void
    {
        Cache::forget(CacheKeys::CATEGORY_LIST);
        $this->bumpListingVersion();
    }
}
