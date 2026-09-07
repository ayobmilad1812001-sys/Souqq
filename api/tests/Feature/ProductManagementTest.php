<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Product CRUD and, most importantly, multi-tenant seller isolation: the rule
 * that a seller can never read into or write into another seller's inventory.
 */
final class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private User $otherSeller;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seller = User::factory()->seller()->create();
        $this->otherSeller = User::factory()->seller()->create();
        $this->category = Category::factory()->create();
    }

    #[Test]
    public function a_seller_can_create_a_product(): void
    {
        $response = $this->actingAs($this->seller)->postJson('/api/v1/products', [
            'name' => 'Mechanical Keyboard',
            'description' => 'Hot-swappable switches.',
            'price' => '249.99',
            'category_id' => $this->category->id,
            'stock_quantity' => 40,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Mechanical Keyboard')
            ->assertJsonPath('data.price', '249.99');

        $this->assertDatabaseHas('products', [
            'name' => 'Mechanical Keyboard',
            'price' => '249.99',
            // Ownership is taken from the authenticated user, never the payload.
            'seller_id' => $this->seller->id,
        ]);
    }

    #[Test]
    public function a_seller_cannot_assign_a_product_to_another_seller(): void
    {
        $this->actingAs($this->seller)->postJson('/api/v1/products', [
            'name' => 'Smuggled Item',
            'description' => 'Trying to plant this in another shop.',
            'price' => '10.00',
            'category_id' => $this->category->id,
            'stock_quantity' => 1,
            'seller_id' => $this->otherSeller->id,
        ])->assertCreated();

        $this->assertDatabaseHas('products', [
            'name' => 'Smuggled Item',
            'seller_id' => $this->seller->id,
        ]);
        $this->assertDatabaseMissing('products', [
            'name' => 'Smuggled Item',
            'seller_id' => $this->otherSeller->id,
        ]);
    }

    #[Test]
    public function a_customer_cannot_create_a_product(): void
    {
        $this->actingAs(User::factory()->customer()->create())
            ->postJson('/api/v1/products', [
                'name' => 'Nope',
                'description' => 'Customers do not sell.',
                'price' => '10.00',
                'category_id' => $this->category->id,
                'stock_quantity' => 1,
            ])
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function a_guest_cannot_create_a_product(): void
    {
        $this->postJson('/api/v1/products', [])->assertUnauthorized();
    }

    #[Test]
    public function product_creation_is_validated(): void
    {
        $this->actingAs($this->seller)
            ->postJson('/api/v1/products', [
                'name' => '',
                'price' => '-5',
                'category_id' => 999999,
                'stock_quantity' => -1,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'description', 'price', 'category_id', 'stock_quantity']);
    }

    /**
     * Guards the DECIMAL(10,2) column: a third decimal place must be rejected
     * outright rather than silently rounded on insert.
     */
    #[Test]
    public function a_price_with_more_than_two_decimals_is_rejected(): void
    {
        $this->actingAs($this->seller)
            ->postJson('/api/v1/products', [
                'name' => 'Over-precise',
                'description' => 'Three decimal places.',
                'price' => '10.999',
                'category_id' => $this->category->id,
                'stock_quantity' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('price');
    }

    #[Test]
    public function a_duplicate_sku_is_rejected(): void
    {
        Product::factory()->create(['sku' => 'TAKEN-SKU']);

        $this->actingAs($this->seller)
            ->postJson('/api/v1/products', [
                'name' => 'Clash',
                'description' => 'Duplicate SKU.',
                'price' => '10.00',
                'category_id' => $this->category->id,
                'stock_quantity' => 1,
                'sku' => 'TAKEN-SKU',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sku');
    }

    #[Test]
    public function a_seller_can_update_their_own_product(): void
    {
        $product = Product::factory()->create(['seller_id' => $this->seller->id]);

        $this->actingAs($this->seller)
            ->patchJson("/api/v1/products/{$product->id}", ['price' => '99.50', 'stock_quantity' => 7])
            ->assertOk()
            ->assertJsonPath('data.price', '99.50');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'price' => '99.50',
            'stock_quantity' => 7,
        ]);
    }

    #[Test]
    public function a_seller_cannot_update_another_sellers_product(): void
    {
        $product = Product::factory()->create([
            'seller_id' => $this->otherSeller->id,
            'price' => '10.00',
        ]);

        $this->actingAs($this->seller)
            ->patchJson("/api/v1/products/{$product->id}", ['price' => '0.01'])
            ->assertForbidden();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'price' => '10.00']);
    }

    #[Test]
    public function a_seller_cannot_delete_another_sellers_product(): void
    {
        $product = Product::factory()->create(['seller_id' => $this->otherSeller->id]);

        $this->actingAs($this->seller)
            ->deleteJson("/api/v1/products/{$product->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    #[Test]
    public function a_seller_can_delete_their_own_unsold_product(): void
    {
        $product = Product::factory()->create(['seller_id' => $this->seller->id]);

        $this->actingAs($this->seller)
            ->deleteJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    /**
     * A product referenced by an order is financial history. Deleting it would
     * break every invoice that cites it, so it is deactivated instead.
     */
    #[Test]
    public function deleting_a_sold_product_deactivates_it_instead(): void
    {
        $product = Product::factory()->create(['seller_id' => $this->seller->id]);
        OrderItem::factory()->create([
            'order_id' => Order::factory()->create()->id,
            'product_id' => $product->id,
        ]);

        $this->actingAs($this->seller)
            ->deleteJson("/api/v1/products/{$product->id}")
            ->assertOk();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'is_active' => false]);
    }

    #[Test]
    public function an_admin_can_manage_any_sellers_product(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['seller_id' => $this->seller->id]);

        $this->actingAs($admin)
            ->patchJson("/api/v1/products/{$product->id}", ['is_active' => false])
            ->assertOk();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'is_active' => false]);
    }

    #[Test]
    public function a_seller_sees_only_their_own_sales_statistics(): void
    {
        Product::factory()->count(3)->create(['seller_id' => $this->seller->id]);
        Product::factory()->count(5)->create(['seller_id' => $this->otherSeller->id]);

        $this->actingAs($this->seller)
            ->getJson('/api/v1/stats')
            ->assertOk()
            ->assertJsonPath('data.scope', 'seller')
            ->assertJsonPath('data.products_total', 3);
    }
}
