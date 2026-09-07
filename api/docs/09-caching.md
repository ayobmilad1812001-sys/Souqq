# 09 — Caching & Invalidation

Redis-backed read-through caching. The hard half is not caching — it is **invalidation you can trust**.

## Files

| File | Role |
| --- | --- |
| `app/Support/CacheKeys.php` | Every key is minted here |
| `app/Services/CatalogCache.php` | Read-through + invalidation rules |
| `app/Observers/ProductObserver.php` | Purge on product write |
| `app/Observers/CategoryObserver.php` | Purge on category write |
| `app/Observers/ReviewObserver.php` | Purge on review write |
| `tests/Feature/CatalogCacheTest.php` | 9 tests |

---

## What is cached

| Data | Key | TTL |
| --- | --- | --- |
| Category list | `categories:all` | 600s |
| Single product | `products:show:{id}` | 600s |
| Product listing | `products:index:v{N}:{md5 of filters}` | 600s |
| Listing version counter | `products:index:version` | forever |

---

## Rule 1 — every key is minted in one place

```php
final class CacheKeys
{
    public const CATEGORY_LIST = 'categories:all';

    public static function product(int $productId): string
    {
        return "products:show:{$productId}";
    }

    public static function productIndex(array $filters, int $version): string
    {
        ksort($filters);

        return sprintf('products:index:v%d:%s', $version, md5(json_encode($filters, JSON_THROW_ON_ERROR)));
    }
}
```

Invalidation is only trustworthy if the code that **writes** a key and the code
that **forgets** it agree on the exact string. A hand-built key in one service
and a slightly different hand-built key in another is the single largest source
of stale-cache bugs in real applications. Centralising key construction removes
the possibility.

Note `ksort($filters)` — `?sort=price_asc&search=x` and `?search=x&sort=price_asc`
are the same query and must produce the same key.

---

## Rule 2 — two shapes of data need two different tools

### Single-entity reads → forget the key

A product or the category list has **one deterministic key**. A write simply
forgets it. Exact, immediate, cheap.

```php
public function forgetProduct(int $productId): void
{
    Cache::forget(CacheKeys::product($productId));
    Cache::forget(CacheKeys::productRating($productId));
    $this->bumpListingVersion();
}
```

### Listings → a versioned namespace

Listings are cached **per filter combination**, so one product edit can invalidate
an unbounded number of keys. Two obvious approaches both fail:

| Approach | Why it fails |
| --- | --- |
| `KEYS products:index:*` then delete | `KEYS` is O(n) over the whole keyspace and blocks Redis. Never use it in production. |
| `Cache::tags()` | Unavailable on the plain `redis` store, and tag bookkeeping adds a write per cached entry. |

So listings live in a **versioned namespace**:

```php
public const PRODUCT_INDEX_VERSION = 'products:index:version';

public function bumpListingVersion(): void
{
    $this->listingVersion();                          // ensure it exists
    Cache::increment(CacheKeys::PRODUCT_INDEX_VERSION);
}
```

Every listing key embeds the current version:

```
products:index:v7:a3f5...
```

Incrementing to `v8` makes **every** previously cached listing unreachable in
**O(1)**. The orphaned keys are never read again and fall out on their own TTL.

`Cache::increment` is atomic in Redis, so concurrent product saves cannot land on
the same version.

The counter itself uses `rememberForever` — if it expired, old listing keys would
become reachable again, which is exactly the bug this design exists to prevent.

### The trade-off, stated plainly

One product edit cold-starts **every** listing query.

That is the right way round for a marketplace: **serving a sold-out or mispriced
product is far more expensive than recomputing a listing.** A stale listing costs
a customer, a refund, and trust. A cache miss costs a few milliseconds.

---

## Rule 3 — invalidation hangs off **observers**, not services

```php
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
```

This is the decision that makes the cache trustworthy.

Attaching invalidation to `ProductService::update()` would cover exactly one
write path. Attaching it to the **model** covers all of them:

- a seller updating a product
- an admin editing it
- the **stock decrement inside checkout** (`InventoryService::decrement`)
- the restock on cancellation
- a seeder, a console command, a future feature nobody has written yet

There is no code path that can leave a stale price or stock figure in Redis,
because there is no way to write a `Product` without firing `saved`.

`ReviewObserver` does the same for the cached average rating and review count.

---

## Personalised views bypass the cache entirely

```php
$isPersonalised = isset($filters['mine']) || ($viewer?->isAdmin() ?? false);

if ($isPersonalised) {
    return $this->query($filters, $viewer)->paginate($perPage, page: $page);
}
```

A seller's `?mine=1` listing contains their **inactive** products; an admin's view
contains everyone's. Writing either into the shared namespace would be a data
leak — another user could hit the same key and read inventory they should never
see.

These views are not cached at all. They are low-volume (dashboards, not shopper
traffic), so the cost is negligible and the risk is eliminated rather than
mitigated.

Tested by `personalised_listings_are_not_written_to_the_shared_cache`.

---

## Cache configuration

```php
'redis' => [
    'driver'          => 'redis',
    'connection'      => env('REDIS_CACHE_CONNECTION', 'cache'),
    'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'default'),
],
```

The cache uses Redis database **1**, the queue uses database **0**:

```php
'cache' => [..., 'database' => env('REDIS_CACHE_DB', '1')],
```

Separating them means `php artisan cache:clear` cannot wipe queued jobs. Sharing
one database is a mistake that surfaces the first time someone clears the cache
during an incident and loses every pending order confirmation.

Tests run with `CACHE_STORE=array`, which behaves identically for `remember`,
`forget` and `increment` but needs no Redis server.

---

## Tests

```
✓ the category list is cached
✓ creating a category purges the cached list
✓ the single product read is cached
✓ updating a product serves the new price immediately
✓ updating a product invalidates cached listings
✓ a product write bumps the listing namespace version
✓ a deleted product disappears from listings at once
✓ a stock change invalidates the cached product
✓ personalised listings are not written to the shared cache
```

The two worth reading:

**`updating_a_product_serves_the_new_price_immediately`** — reads a product at
`100.00`, updates it to `75.00`, reads again and asserts `75.00`. This is the
regression that a naive cache produces: the detail endpoint quoting a stale price
for ten minutes after a sale started.

**`a_stock_change_invalidates_the_cached_product`** — the checkout path. Stock
goes 5 → 1 and the very next read reflects it.
