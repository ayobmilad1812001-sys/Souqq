<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Support\CacheKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cache behaviour and, more importantly, invalidation (PRD 4.5).
 *
 * A cache that never goes stale is the whole point: serving a sold-out or
 * mispriced product is worse than serving nothing at all.
 */
final class CatalogCacheTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_category_list_is_cached(): void
    {
        Category::factory()->count(2)->create();

        $this->getJson('/api/v1/categories')->assertOk();

        $this->assertTrue(Cache::has(CacheKeys::CATEGORY_LIST));
    }

    #[Test]
    public function creating_a_category_purges_the_cached_list(): void
    {
        Category::factory()->create();
        $this->getJson('/api/v1/categories')->assertOk()->assertJsonCount(1, 'data');

        Category::factory()->create();

        // The observer forgot the key, so the next read rebuilds it.
        $this->assertFalse(Cache::has(CacheKeys::CATEGORY_LIST));
        $this->getJson('/api/v1/categories')->assertOk()->assertJsonCount(2, 'data');
    }

    #[Test]
    public function the_single_product_read_is_cached(): void
    {
        $product = Product::factory()->create();

        $this->getJson("/api/v1/products/{$product->id}")->assertOk();

        $this->assertTrue(Cache::has(CacheKeys::product($product->id)));
    }

    /**
     * The regression this guards: a seller drops the price, and the detail
     * endpoint keeps quoting the old one until the TTL expires.
     */
    #[Test]
    public function updating_a_product_serves_the_new_price_immediately(): void
    {
        $seller = User::factory()->seller()->create();
        $product = Product::factory()->priced('100.00')->create(['seller_id' => $seller->id]);

        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.price', '100.00');

        $this->actingAs($seller)
            ->patchJson("/api/v1/products/{$product->id}", ['price' => '75.00'])
            ->assertOk();

        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.price', '75.00');
    }

    #[Test]
    public function updating_a_product_invalidates_cached_listings(): void
    {
        $seller = User::factory()->seller()->create();
        Product::factory()->create(['seller_id' => $seller->id, 'name' => 'Original Name']);

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Original Name');

        $product = Product::query()->sole();

        $this->actingAs($seller)
            ->patchJson("/api/v1/products/{$product->id}", ['name' => 'Renamed'])
            ->assertOk();

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Renamed');
    }

    /**
     * Listings live in a versioned namespace because Redis cannot wildcard
     * delete. A write must move the version pointer.
     */
    #[Test]
    public function a_product_write_bumps_the_listing_namespace_version(): void
    {
        Product::factory()->create();
        $this->getJson('/api/v1/products')->assertOk();

        $before = (int) Cache::get(CacheKeys::PRODUCT_INDEX_VERSION);

        Product::factory()->create();

        $this->assertGreaterThan($before, (int) Cache::get(CacheKeys::PRODUCT_INDEX_VERSION));
    }

    #[Test]
    public function a_deleted_product_disappears_from_listings_at_once(): void
    {
        $seller = User::factory()->seller()->create();
        $product = Product::factory()->create(['seller_id' => $seller->id]);

        $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(1, 'data');

        $this->actingAs($seller)->deleteJson("/api/v1/products/{$product->id}")->assertOk();

        $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(0, 'data');
    }

    /**
     * Checkout decrements stock, which must be reflected in the catalogue the
     * moment the next shopper looks.
     */
    #[Test]
    public function a_stock_change_invalidates_the_cached_product(): void
    {
        $product = Product::factory()->withStock(5)->create();

        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.stock_quantity', 5);

        $product->update(['stock_quantity' => 1]);

        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.stock_quantity', 1);
    }

    /**
     * Seller and admin views are user-specific, so they must bypass the shared
     * cache rather than write one seller's inventory into a key another user
     * could read.
     */
    #[Test]
    public function personalised_listings_are_not_written_to_the_shared_cache(): void
    {
        $seller = User::factory()->seller()->create();
        Product::factory()->count(2)->create(['seller_id' => $seller->id]);
        Product::factory()->count(3)->create();

        $this->actingAs($seller)->getJson('/api/v1/products?mine=1')->assertOk()->assertJsonCount(2, 'data');

        // A guest browsing the same catalogue still sees everything active.
        $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(5, 'data');
    }
}
