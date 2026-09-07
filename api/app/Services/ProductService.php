<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/**
 * Catalogue reads and writes. Reads go through CatalogCache; writes rely on
 * ProductObserver to purge the affected keys, so no caller has to remember to
 * invalidate anything.
 */
final readonly class ProductService
{
    public function __construct(private CatalogCache $cache) {}

    /**
     * Paginated, filtered catalogue listing.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<Product>
     */
    public function paginate(array $filters, ?User $viewer = null): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 15);
        $page = (int) ($filters['page'] ?? 1);

        // Sellers browsing their own inventory need to see inactive products,
        // and admins see everything. Those views are user-specific, so they
        // bypass the shared cache entirely rather than poisoning it.
        $isPersonalised = isset($filters['mine']) || ($viewer?->isAdmin() ?? false);

        if ($isPersonalised) {
            return $this->query($filters, $viewer)->paginate($perPage, page: $page);
        }

        // Cache key includes page + per_page: two pages of the same filter set
        // are different payloads.
        return $this->cache->rememberListing(
            $filters + ['page' => $page, 'per_page' => $perPage],
            fn (): LengthAwarePaginator => $this->query($filters, null)->paginate($perPage, page: $page)
        );
    }

    /** Cached single-product read for the public product page. */
    public function find(int $productId): ?Product
    {
        return $this->cache->rememberProduct(
            $productId,
            static fn (): ?Product => Product::query()
                ->with(['category', 'seller:id,name'])
                ->withCount('reviews')
                ->withAvg('reviews', 'rating')
                ->find($productId)
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $seller, array $attributes): Product
    {
        return Product::query()->create([
            ...$attributes,
            // A seller may never assign a product to someone else, and an admin
            // creating on a sellers behalf must say so explicitly.
            'seller_id' => $attributes['seller_id'] ?? $seller->id,
            'sku' => $attributes['sku'] ?? $this->generateSku($attributes['name']),
        ])->load('category');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Product $product, array $attributes): Product
    {
        // seller_id is never mass-assignable from a request: reassigning
        // ownership is an admin-only operation with its own endpoint.
        unset($attributes['seller_id']);

        $product->update($attributes);

        return $product->fresh(['category']);
    }

    public function delete(Product $product): void
    {
        // Products referenced by an order are restricted at the FK level, so a
        // sold product is soft-retired by deactivating it instead.
        if ($product->orderItems()->exists()) {
            $product->update(['is_active' => false]);

            return;
        }

        $product->delete();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Database\Eloquent\Builder<Product>
     */
    private function query(array $filters, ?User $viewer): \Illuminate\Database\Eloquent\Builder
    {
        return Product::query()
            // Eager load to keep listing serialisation free of N+1 queries.
            ->with(['category', 'seller:id,name'])
            ->when(
                ! ($viewer?->isAdmin() ?? false) && ! isset($filters['mine']),
                fn ($query) => $query->active()
            )
            ->when(isset($filters['mine']) && $viewer, fn ($query) => $query->ownedBy($viewer->id))
            ->search($filters['search'] ?? null)
            ->inCategory($filters['category'] ?? null)
            ->priceBetween($filters['min_price'] ?? null, $filters['max_price'] ?? null)
            ->when(
                isset($filters['in_stock']) && $filters['in_stock'],
                fn ($query) => $query->where('stock_quantity', '>', 0)
            )
            ->sorted($filters['sort'] ?? null);
    }

    /** Deterministic, collision-resistant SKU for sellers who do not supply one. */
    private function generateSku(string $name): string
    {
        return strtoupper(Str::slug(Str::limit($name, 12, ''), '-').'-'.Str::random(6));
    }
}
