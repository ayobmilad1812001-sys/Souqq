<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CartManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::factory()->customer()->create();
    }

    #[Test]
    public function a_cart_is_created_on_first_access_and_starts_empty(): void
    {
        $this->actingAs($this->customer)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.items_count', 0)
            ->assertJsonPath('data.subtotal', '0.00')
            ->assertJsonPath('data.estimated_shipping', '0.00');

        $this->assertDatabaseHas('carts', ['user_id' => $this->customer->id]);
    }

    #[Test]
    public function repeated_access_never_creates_a_second_cart(): void
    {
        $this->actingAs($this->customer)->getJson('/api/v1/cart')->assertOk();
        $this->actingAs($this->customer)->getJson('/api/v1/cart')->assertOk();

        $this->assertSame(1, $this->customer->cart()->count());
    }

    #[Test]
    public function a_customer_can_add_an_item(): void
    {
        $product = Product::factory()->priced('25.00')->withStock(10)->create();

        $this->actingAs($this->customer)
            ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 2])
            ->assertCreated()
            ->assertJsonPath('data.items_count', 1)
            ->assertJsonPath('data.total_quantity', 2)
            ->assertJsonPath('data.subtotal', '50.00');
    }

    /**
     * The cart carries a unique(cart_id, product_id) index, so adding the same
     * product twice must accumulate rather than duplicate the line.
     */
    #[Test]
    public function adding_the_same_product_twice_increases_the_quantity(): void
    {
        $product = Product::factory()->priced('10.00')->withStock(10)->create();

        $this->actingAs($this->customer)
            ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 2])
            ->assertCreated();

        $this->actingAs($this->customer)
            ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 3])
            ->assertCreated()
            ->assertJsonPath('data.items_count', 1)
            ->assertJsonPath('data.total_quantity', 5)
            ->assertJsonPath('data.subtotal', '50.00');

        $this->assertSame(1, CartItem::query()->count());
    }

    #[Test]
    public function it_refuses_to_add_more_than_the_available_stock(): void
    {
        $product = Product::factory()->withStock(3)->create();

        $this->actingAs($this->customer)
            ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 4])
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.available', 3)
            ->assertJsonPath('errors.requested', 4);
    }

    #[Test]
    public function accumulated_quantity_is_checked_against_stock(): void
    {
        $product = Product::factory()->withStock(5)->create();

        $this->actingAs($this->customer)
            ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 4])
            ->assertCreated();

        // 4 already in the cart plus 2 more exceeds the 5 available.
        $this->actingAs($this->customer)
            ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 2])
            ->assertStatus(409);
    }

    #[Test]
    public function an_inactive_product_cannot_be_added(): void
    {
        $product = Product::factory()->inactive()->create();

        $this->actingAs($this->customer)
            ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_id');
    }

    #[Test]
    public function adding_an_item_is_validated(): void
    {
        $this->actingAs($this->customer)
            ->postJson('/api/v1/cart/items', ['product_id' => 999999, 'quantity' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['product_id', 'quantity']);
    }

    #[Test]
    public function a_customer_can_change_a_line_quantity(): void
    {
        $product = Product::factory()->priced('10.00')->withStock(10)->create();
        $this->actingAs($this->customer)
            ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1]);

        $item = CartItem::query()->sole();

        $this->actingAs($this->customer)
            ->patchJson("/api/v1/cart/items/{$item->id}", ['quantity' => 4])
            ->assertOk()
            ->assertJsonPath('data.subtotal', '40.00');
    }

    #[Test]
    public function a_customer_can_remove_a_line(): void
    {
        $product = Product::factory()->withStock(10)->create();
        $this->actingAs($this->customer)
            ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1]);

        $item = CartItem::query()->sole();

        $this->actingAs($this->customer)
            ->deleteJson("/api/v1/cart/items/{$item->id}")
            ->assertOk()
            ->assertJsonPath('data.items_count', 0);

        $this->assertDatabaseMissing('cart_items', ['id' => $item->id]);
    }

    /**
     * Cross-tenant guard: carts are private, and there is deliberately no admin
     * override on CartItemPolicy.
     */
    #[Test]
    public function a_customer_cannot_touch_another_customers_cart_line(): void
    {
        $intruder = User::factory()->customer()->create();
        $product = Product::factory()->withStock(10)->create();

        $this->actingAs($this->customer)
            ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1]);

        $item = CartItem::query()->sole();

        $this->actingAs($intruder)
            ->patchJson("/api/v1/cart/items/{$item->id}", ['quantity' => 99])
            ->assertForbidden();

        $this->actingAs($intruder)
            ->deleteJson("/api/v1/cart/items/{$item->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('cart_items', ['id' => $item->id, 'quantity' => 1]);
    }

    #[Test]
    public function an_admin_cannot_edit_someone_elses_cart_either(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->withStock(10)->create();

        $this->actingAs($this->customer)
            ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1]);

        $this->actingAs($admin)
            ->deleteJson('/api/v1/cart/items/'.CartItem::query()->sole()->id)
            ->assertForbidden();
    }

    #[Test]
    public function a_guest_has_no_cart(): void
    {
        $this->getJson('/api/v1/cart')->assertUnauthorized();
    }

    #[Test]
    public function the_cart_reports_free_shipping_above_the_threshold(): void
    {
        config(['marketplace.shipping.free_threshold' => '500.00']);
        $product = Product::factory()->priced('300.00')->withStock(10)->create();

        $this->actingAs($this->customer)
            ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 2])
            ->assertCreated()
            ->assertJsonPath('data.subtotal', '600.00')
            ->assertJsonPath('data.estimated_shipping', '0.00')
            ->assertJsonPath('data.estimated_total', '600.00');
    }
}
