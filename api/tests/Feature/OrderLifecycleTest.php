<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Status transitions, who is allowed to make them, and what happens to stock
 * when an order is cancelled.
 */
final class OrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::factory()->customer()->create();
        $this->seller = User::factory()->seller()->create();
    }

    #[Test]
    public function a_customer_sees_only_their_own_orders(): void
    {
        Order::factory()->count(2)->create(['user_id' => $this->customer->id]);
        Order::factory()->count(3)->create();

        $this->actingAs($this->customer)
            ->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    #[Test]
    public function a_seller_sees_only_orders_containing_their_products(): void
    {
        $mine = $this->orderContainingProductOf($this->seller);
        $this->orderContainingProductOf(User::factory()->seller()->create());

        $response = $this->actingAs($this->seller)->getJson('/api/v1/orders')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($mine->id, $response->json('data.0.id'));
    }

    #[Test]
    public function an_admin_sees_every_order(): void
    {
        Order::factory()->count(4)->create();

        $this->actingAs(User::factory()->admin()->create())
            ->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonCount(4, 'data');
    }

    #[Test]
    public function orders_can_be_filtered_by_status(): void
    {
        Order::factory()->count(2)->create(['user_id' => $this->customer->id]);
        Order::factory()->status(OrderStatus::Shipped)->create(['user_id' => $this->customer->id]);

        $this->actingAs($this->customer)
            ->getJson('/api/v1/orders?status=shipped')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function a_customer_cannot_read_someone_elses_order(): void
    {
        $order = Order::factory()->create();

        $this->actingAs($this->customer)
            ->getJson("/api/v1/orders/{$order->id}")
            ->assertForbidden();
    }

    #[Test]
    public function a_seller_can_read_an_order_containing_their_product(): void
    {
        $order = $this->orderContainingProductOf($this->seller);

        $this->actingAs($this->seller)
            ->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $order->id);
    }

    #[Test]
    public function a_seller_can_advance_an_order_through_fulfilment(): void
    {
        $order = $this->orderContainingProductOf($this->seller);

        $this->actingAs($this->seller)
            ->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'confirmed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'confirmed']);
    }

    #[Test]
    public function the_response_advertises_the_legal_next_statuses(): void
    {
        $order = $this->orderContainingProductOf($this->seller);

        $this->actingAs($this->seller)
            ->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.allowed_transitions', ['confirmed', 'cancelled']);
    }

    #[Test]
    public function an_illegal_transition_is_rejected(): void
    {
        $order = $this->orderContainingProductOf($this->seller);

        $this->actingAs($this->seller)
            ->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'delivered'])
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.from', 'pending')
            ->assertJsonPath('errors.to', 'delivered');

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending']);
    }

    #[Test]
    public function an_unknown_status_value_is_a_validation_error(): void
    {
        $order = $this->orderContainingProductOf($this->seller);

        $this->actingAs($this->seller)
            ->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'teleported'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    #[Test]
    public function a_seller_cannot_change_the_status_of_an_unrelated_order(): void
    {
        $order = $this->orderContainingProductOf(User::factory()->seller()->create());

        $this->actingAs($this->seller)
            ->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'confirmed'])
            ->assertForbidden();
    }

    #[Test]
    public function a_customer_cannot_drive_the_fulfilment_status(): void
    {
        $order = Order::factory()->create(['user_id' => $this->customer->id]);

        $this->actingAs($this->customer)
            ->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'shipped'])
            ->assertForbidden();
    }

    /**
     * A seller must not be able to cancel an order that may also contain
     * another seller's goods -- cancellation is a platform decision.
     */
    #[Test]
    public function a_seller_cannot_cancel_an_order(): void
    {
        $order = $this->orderContainingProductOf($this->seller);

        $this->actingAs($this->seller)
            ->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'cancelled'])
            ->assertForbidden();
    }

    #[Test]
    public function a_customer_can_cancel_their_own_pending_order(): void
    {
        $order = Order::factory()->create(['user_id' => $this->customer->id]);

        $this->actingAs($this->customer)
            ->patchJson("/api/v1/orders/{$order->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertNotNull($order->fresh()->cancelled_at);
    }

    /** Cancelling before dispatch must put the reserved units back on sale. */
    #[Test]
    public function cancelling_returns_stock_to_the_catalogue(): void
    {
        $product = Product::factory()->withStock(10)->create(['seller_id' => $this->seller->id]);
        $order = Order::factory()->create(['user_id' => $this->customer->id]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 3,
        ]);
        // Simulate the decrement the checkout would have applied.
        $product->update(['stock_quantity' => 7]);

        $this->actingAs($this->customer)
            ->patchJson("/api/v1/orders/{$order->id}/cancel")
            ->assertOk();

        $this->assertSame(10, $product->fresh()->stock_quantity);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $product->id,
            'order_id' => $order->id,
            'reason' => 'cancellation',
            'quantity_delta' => 3,
        ]);
    }

    #[Test]
    public function a_shipped_order_cannot_be_cancelled_by_the_customer(): void
    {
        $order = Order::factory()->status(OrderStatus::Shipped)->create(['user_id' => $this->customer->id]);

        $this->actingAs($this->customer)
            ->patchJson("/api/v1/orders/{$order->id}/cancel")
            ->assertStatus(409)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function cancellation_is_refused_once_the_window_has_closed(): void
    {
        config(['marketplace.orders.cancellation_window_minutes' => 60]);

        $order = Order::factory()->create([
            'user_id' => $this->customer->id,
            'created_at' => now()->subHours(3),
        ]);

        $this->actingAs($this->customer)
            ->patchJson("/api/v1/orders/{$order->id}/cancel")
            ->assertStatus(409)
            ->assertJsonPath('errors.reason', 'the 60 minute cancellation window has closed');
    }

    #[Test]
    public function a_customer_cannot_cancel_another_customers_order(): void
    {
        $order = Order::factory()->create();

        $this->actingAs($this->customer)
            ->patchJson("/api/v1/orders/{$order->id}/cancel")
            ->assertForbidden();
    }

    /** Builds an order that contains exactly one product from the given seller. */
    private function orderContainingProductOf(User $seller): Order
    {
        $order = Order::factory()->create(['user_id' => $this->customer->id]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => Product::factory()->create(['seller_id' => $seller->id])->id,
        ]);

        return $order;
    }
}
