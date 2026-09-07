<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Public catalogue: search, filtering, sorting and pagination (PRD 5.2).
 */
final class ProductCatalogTest extends TestCase
{
    use RefreshDatabase;

    private Category $electronics;

    private Category $books;

    protected function setUp(): void
    {
        parent::setUp();

        $this->electronics = Category::factory()->create(['name' => 'Electronics', 'slug' => 'electronics']);
        $this->books = Category::factory()->create(['name' => 'Books', 'slug' => 'books']);
    }

    #[Test]
    public function guests_can_browse_the_catalogue(): void
    {
        Product::factory()->count(3)->create(['category_id' => $this->electronics->id]);

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'success',
                'data' => [['id', 'name', 'price', 'stock_quantity', 'category']],
                'message',
                'meta' => ['current_page', 'total'],
            ]);
    }

    #[Test]
    public function inactive_products_are_hidden_from_shoppers(): void
    {
        Product::factory()->create(['name' => 'Visible', 'category_id' => $this->electronics->id]);
        Product::factory()->inactive()->create(['name' => 'Hidden', 'category_id' => $this->electronics->id]);

        $response = $this->getJson('/api/v1/products')->assertOk();

        $this->assertSame(['Visible'], array_column($response->json('data'), 'name'));
    }

    #[Test]
    public function it_searches_name_and_description(): void
    {
        Product::factory()->create(['name' => 'Wireless Headphones', 'description' => 'Over ear']);
        Product::factory()->create(['name' => 'Desk Lamp', 'description' => 'A wireless charging base']);
        Product::factory()->create(['name' => 'Cotton Shirt', 'description' => 'Plain']);

        $response = $this->getJson('/api/v1/products?search=wireless')->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    /**
     * A search term containing SQL LIKE wildcards must be treated as literal
     * text, not as a pattern that matches every row.
     */
    #[Test]
    public function search_escapes_like_wildcards(): void
    {
        Product::factory()->create(['name' => 'Sale 50% off bundle']);
        Product::factory()->create(['name' => 'Regular item']);

        $response = $this->getJson('/api/v1/products?search='.urlencode('50%'))->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Sale 50% off bundle', $response->json('data.0.name'));
    }

    #[Test]
    public function it_filters_by_category_id_and_by_slug(): void
    {
        Product::factory()->count(2)->create(['category_id' => $this->electronics->id]);
        Product::factory()->create(['category_id' => $this->books->id]);

        $this->getJson("/api/v1/products?category={$this->electronics->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/products?category=books')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function it_filters_by_price_range(): void
    {
        Product::factory()->priced('10.00')->create();
        Product::factory()->priced('50.00')->create();
        Product::factory()->priced('500.00')->create();

        $response = $this->getJson('/api/v1/products?min_price=20&max_price=100')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('50.00', $response->json('data.0.price'));
    }

    #[Test]
    public function it_rejects_a_max_price_below_the_min_price(): void
    {
        $this->getJson('/api/v1/products?min_price=100&max_price=10')
            ->assertStatus(422)
            ->assertJsonValidationErrors('max_price');
    }

    #[Test]
    public function it_sorts_by_price_in_both_directions(): void
    {
        Product::factory()->priced('30.00')->create();
        Product::factory()->priced('10.00')->create();
        Product::factory()->priced('20.00')->create();

        $ascending = $this->getJson('/api/v1/products?sort=price_asc')->assertOk();
        $this->assertSame(['10.00', '20.00', '30.00'], array_column($ascending->json('data'), 'price'));

        $descending = $this->getJson('/api/v1/products?sort=price_desc')->assertOk();
        $this->assertSame(['30.00', '20.00', '10.00'], array_column($descending->json('data'), 'price'));
    }

    #[Test]
    public function it_rejects_an_unknown_sort_key(): void
    {
        $this->getJson('/api/v1/products?sort=price;DROP TABLE products')
            ->assertStatus(422)
            ->assertJsonValidationErrors('sort');
    }

    #[Test]
    public function it_paginates_with_a_capped_page_size(): void
    {
        Product::factory()->count(25)->create();

        $this->getJson('/api/v1/products?per_page=10&page=2')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.total', 25);

        $this->getJson('/api/v1/products?per_page=500')
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page');
    }

    #[Test]
    public function it_can_filter_to_products_that_are_in_stock(): void
    {
        Product::factory()->withStock(5)->create();
        Product::factory()->outOfStock()->create();

        $this->getJson('/api/v1/products?in_stock=1')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function a_single_product_can_be_retrieved_with_its_review_count(): void
    {
        $product = Product::factory()->create();

        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.reviews_count', 0);
    }

    #[Test]
    public function a_missing_product_returns_the_error_envelope(): void
    {
        $this->getJson('/api/v1/products/999999')
            ->assertNotFound()
            ->assertJson(['success' => false, 'message' => 'Resource not found.']);
    }

    #[Test]
    public function an_inactive_product_is_hidden_from_shoppers_but_visible_to_its_seller(): void
    {
        $seller = User::factory()->seller()->create();
        $product = Product::factory()->inactive()->create(['seller_id' => $seller->id]);

        $this->getJson("/api/v1/products/{$product->id}")->assertForbidden();

        $this->actingAs($seller)
            ->getJson("/api/v1/products/{$product->id}")
            ->assertOk();
    }

    #[Test]
    public function a_seller_can_list_only_their_own_inventory(): void
    {
        $seller = User::factory()->seller()->create();
        Product::factory()->count(2)->create(['seller_id' => $seller->id]);
        Product::factory()->count(3)->create();

        $this->actingAs($seller)
            ->getJson('/api/v1/products?mine=1')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
