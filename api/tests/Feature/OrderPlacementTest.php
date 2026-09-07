<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Events\OrderCreated;
use App\Jobs\SendOrderConfirmationJob;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Checkout: the atomic transaction, the stock arithmetic, and the rollback
 * guarantees described in PRD 4.1 and 4.2.
 */
final class OrderPlacementTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::factory()->customer()->create();
        config([
            'marketplace.shipping.flat_rate' => '15.00',
            'marketplace.shipping.free_threshold' => '500.00',
        ]);
    }

    #[Test]
    public function a_customer_can_place_an_order_from_their_cart(): void
    {
        $product = Product::factory()->priced('100.00')->withStock(10)->create();
        $this->addToCart($product, 2);

        $response = $this->actingAs($this->customer)->postJson('/api/v1/orders');

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', OrderStatus::Pending->value)
            ->assertJsonPath('data.subtotal', '200.00')
            ->assertJsonPath('data.shipping_cost', '15.00')
            ->assertJsonPath('data.total', '215.00');

        $this->assertDatabaseHas('orders', [
            'user_id' => $this->customer->id,
            'total' => '215.00',
            'status' => OrderStatus::Pending->value,
        ]);
    }

    #[Test]
    public function placing_an_order_decrements_stock(): void
    {
        $product = Product::factory()->withStock(10)->create();
        $this->addToCart($product, 3);

        $this->actingAs($this->customer)->postJson('/api/v1/orders')->assertCreated();

        $this->assertSame(7, $product->fresh()->stock_quantity);
    }

    #[Test]
    public function placing_an_order_empties_the_cart(): void
    {
        $product = Product::factory()->withStock(10)->create();
        $this->addToCart($product, 1);

        $this->actingAs($this->customer)->postJson('/api/v1/orders')->assertCreated();

        $this->assertSame(0, CartItem::query()->count());
        // The cart itself survives; only its lines are cleared.
        $this->assertDatabaseHas('carts', ['user_id' => $this->customer->id]);
    }

    /**
     * Order lines are a frozen snapshot: repricing the product afterwards must
     * not rewrite the invoice.
     */
    #[Test]
    public function order_items_freeze_the_price_at_checkout(): void
    {
        $product = Product::factory()->priced('50.00')->withStock(10)->create();
        $this->addToCart($product, 2);

        $this->actingAs($this->customer)->postJson('/api/v1/orders')->assertCreated();

        $product->update(['price' => '999.00']);

        $this->assertDatabaseHas('order_items', [
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => '50.00',
            'subtotal' => '100.00',
        ]);
        $this->assertDatabaseHas('orders', ['total' => '115.00']);
    }

    #[Test]
    public function an_order_spanning_several_products_totals_correctly(): void
    {
        $first = Product::factory()->priced('19.99')->withStock(10)->create();
        $second = Product::factory()->priced('0.10')->withStock(10)->create();

        $this->addToCart($first, 2);
        $this->addToCart($second, 3);

        // 39.98 + 0.30 = 40.28 -- a float sum of these does not land exactly.
        $this->actingAs($this->customer)->postJson('/api/v1/orders')
            ->assertCreated()
            ->assertJsonPath('data.subtotal', '40.28')
            ->assertJsonPath('data.total', '55.28');
    }

    #[Test]
    public function an_empty_cart_cannot_be_checked_out(): void
    {
        $this->actingAs($this->customer)
            ->postJson('/api/v1/orders')
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Your cart is empty. Add items before placing an order.');

        $this->assertSame(0, Order::query()->count());
    }

    /**
     * Stock can fall between browsing and paying. The authoritative check runs
     * inside the transaction, under the row lock, and rejects the order.
     */
    #[Test]
    public function an_order_is_rejected_when_stock_dropped_after_the_item_was_carted(): void
    {
        $product = Product::factory()->withStock(5)->create();
        $this->addToCart($product, 5);

        // Someone else bought four units in the meantime.
        $product->update(['stock_quantity' => 1]);

        $this->actingAs($this->customer)
            ->postJson('/api/v1/orders')
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.requested', 5)
            ->assertJsonPath('errors.available', 1);
    }

    /**
     * Availability for every line is verified before a single row is written,
     * so a sold-out line leaves no trace: no order, no stock movement, and the
     * cart still intact for a retry.
     */
    #[Test]
    public function a_rejected_order_persists_nothing(): void
    {
        $ok = Product::factory()->withStock(10)->create();
        $doomed = Product::factory()->withStock(10)->create();

        $this->addToCart($ok, 2);
        $this->addToCart($doomed, 2);

        // The second product sells out before checkout completes.
        $doomed->update(['stock_quantity' => 0]);

        $this->actingAs($this->customer)->postJson('/api/v1/orders')->assertStatus(409);

        $this->assertSame(0, Order::query()->count(), 'No order row may survive a rollback.');
        $this->assertSame(0, OrderItem::query()->count());
        $this->assertSame(10, $ok->fresh()->stock_quantity, 'Stock for the healthy line must be restored.');
        $this->assertSame(2, CartItem::query()->count(), 'The cart must be left intact so the shopper can retry.');
    }

    #[Test]
    public function an_order_is_rejected_if_a_carted_product_was_deactivated(): void
    {
        $product = Product::factory()->withStock(10)->create();
        $this->addToCart($product, 1);

        $product->update(['is_active' => false]);

        $this->actingAs($this->customer)
            ->postJson('/api/v1/orders')
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(0, Order::query()->count());
    }

    /**
     * Two shoppers, one remaining unit. Executed sequentially here -- true
     * parallel execution needs a real MySQL connection per thread -- but this
     * still proves the second checkout reads the post-commit stock figure and
     * refuses, which is exactly what the row lock guarantees under contention.
     */
    #[Test]
    public function two_customers_cannot_both_buy_the_last_unit(): void
    {
        $product = Product::factory()->withStock(1)->create();
        $rival = User::factory()->customer()->create();

        $this->addToCart($product, 1);
        $this->addToCart($product, 1, $rival);

        $this->actingAs($this->customer)->postJson('/api/v1/orders')->assertCreated();
        $this->actingAs($rival)->postJson('/api/v1/orders')->assertStatus(409);

        $this->assertSame(0, $product->fresh()->stock_quantity, 'Stock must never go negative.');
        $this->assertSame(1, Order::query()->count());
    }

    #[Test]
    public function placing_an_order_dispatches_the_order_created_event(): void
    {
        Event::fake([OrderCreated::class]);

        $product = Product::factory()->withStock(5)->create();
        $this->addToCart($product, 1);

        $this->actingAs($this->customer)->postJson('/api/v1/orders')->assertCreated();

        Event::assertDispatched(OrderCreated::class);
    }

    /** The confirmation email must never be sent inline on the request thread. */
    #[Test]
    public function the_confirmation_email_is_queued_rather_than_sent_inline(): void
    {
        Bus::fake();

        $product = Product::factory()->withStock(5)->create();
        $this->addToCart($product, 1);

        $this->actingAs($this->customer)->postJson('/api/v1/orders')->assertCreated();

        Bus::assertDispatched(SendOrderConfirmationJob::class);
    }

    #[Test]
    public function a_guest_cannot_place_an_order(): void
    {
        $this->postJson('/api/v1/orders')->assertUnauthorized();
    }

    /**
     * The real rollback guarantee. A failure is injected while the *second*
     * line is being written -- after the order row, the first line and the
     * first stock decrement have already been issued inside the transaction.
     * Every one of those writes must disappear.
     */
    #[Test]
    public function a_mid_transaction_failure_rolls_back_every_write(): void
    {
        $first = Product::factory()->withStock(10)->create();
        $second = Product::factory()->withStock(10)->create();

        $this->addToCart($first, 2);
        $this->addToCart($second, 3);

        OrderItem::creating(function (): void {
            // The first line is already written (and visible inside this
            // transaction), so this fires while persisting the second.
            if (OrderItem::query()->exists()) {
                throw new RuntimeException('Simulated database failure mid-checkout.');
            }
        });

        // Note: the assertion is made after the try block. PHPUnit's own
        // failure exception extends RuntimeException, so calling fail() inside
        // the try would be swallowed by the catch below.
        $aborted = false;

        try {
            app(OrderService::class)->place($this->customer);
        } catch (RuntimeException) {
            $aborted = true;
        }

        $this->assertTrue($aborted, 'The injected failure should have aborted the checkout.');

        $this->assertSame(0, Order::query()->count(), 'The order row must be rolled back.');
        $this->assertSame(0, OrderItem::query()->count(), 'The first line must be rolled back.');
        $this->assertSame(10, $first->fresh()->stock_quantity, 'The first stock decrement must be undone.');
        $this->assertSame(0, InventoryMovement::query()->count(), 'The ledger write must be undone.');
        $this->assertSame(2, CartItem::query()->count(), 'The cart must survive a failed checkout.');
    }

    private function addToCart(Product $product, int $quantity, ?User $user = null): void
    {
        $user ??= $this->customer;

        CartItem::query()->create([
            'cart_id' => Cart::query()->firstOrCreate(['user_id' => $user->id])->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
        ]);
    }
}
