<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Events\OrderCreated;
use App\Events\OrderStatusChanged;
use App\Exceptions\EmptyCartException;
use App\Exceptions\InvalidStatusTransitionException;
use App\Exceptions\OrderNotCancellableException;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Checkout and order lifecycle. This is the only class permitted to create
 * orders or change their status.
 */
final readonly class OrderService
{
    public function __construct(
        private CartService $cartService,
        private InventoryService $inventory,
        private PricingService $pricing,
    ) {}

    /**
     * Turn the customer's cart into an order.
     *
     * ATOMICITY (PRD 4.1)
     * -------------------
     * Everything below runs inside one DB::transaction. If any step throws --
     * an item went out of stock, a product was deactivated, the database died
     * mid-write -- the whole thing rolls back: no orphan order, no phantom
     * stock decrement, and the cart is left exactly as the shopper had it so
     * they can retry.
     *
     * Sequence: validate cart -> lock products -> verify stock -> create order
     * -> create items -> decrement stock -> clear cart.
     *
     * The OrderCreated event fires only *after* commit, so no listener can ever
     * observe (or email about) an order that later rolled back.
     */
    public function place(User $user): Order
    {
        $cart = $this->cartService->forUser($user);

        // 1. Cheap pre-flight check outside the transaction. Every
        //    authoritative check is repeated inside it, under lock.
        if ($cart->isEmpty()) {
            throw new EmptyCartException;
        }

        $order = DB::transaction(function () use ($user, $cart): Order {
            // 2. Take row locks on every product in the cart, in a deterministic
            //    id order, and hold them until this transaction commits. From
            //    here on the stock figures we read cannot be changed by anyone
            //    else -- concurrent checkouts for the same SKU queue behind us.
            $products = $this->inventory->lockProducts(
                $cart->items->pluck('product_id')->all()
            );

            $lines = [];

            foreach ($cart->items as $item) {
                /** @var Product $product */
                $product = $products[$item->product_id];

                // 3. Verify availability against the freshly locked row, not
                //    against the copy the shopper loaded minutes ago.
                $this->inventory->assertAvailable($product, $item->quantity);

                $lines[] = [
                    'product' => $product,
                    'quantity' => $item->quantity,
                    // Freeze the price at checkout time.
                    'unit_price' => $product->priceAsMoney(),
                ];
            }

            // 4. All money is computed once, from the frozen unit prices.
            $breakdown = $this->pricing->breakdown(array_map(
                static fn (array $line): array => [
                    'unit_price' => $line['unit_price'],
                    'quantity' => $line['quantity'],
                ],
                $lines
            ));

            $order = Order::query()->create([
                'user_id' => $user->id,
                'status' => OrderStatus::Pending,
                'subtotal' => $breakdown['subtotal']->toDecimalString(),
                'shipping_cost' => $breakdown['shipping']->toDecimalString(),
                'total' => $breakdown['total']->toDecimalString(),
            ]);

            // 5. Persist line items and draw down stock under the same lock.
            foreach ($lines as $line) {
                /** @var Money $unitPrice */
                $unitPrice = $line['unit_price'];

                $order->items()->create([
                    'product_id' => $line['product']->id,
                    'quantity' => $line['quantity'],
                    'unit_price' => $unitPrice->toDecimalString(),
                    'subtotal' => $this->pricing
                        ->lineSubtotal($unitPrice, $line['quantity'])
                        ->toDecimalString(),
                ]);

                $this->inventory->decrement($line['product'], $line['quantity'], $order->id);
            }

            // 6. The cart is emptied inside the transaction: if anything after
            //    this point fails, the shopper gets their cart back.
            $this->cartService->clear($cart);

            return $order;
        });

        // Post-commit side effects (confirmation email, analytics) are handled
        // by listeners on this event, none of which run in the request path.
        OrderCreated::dispatch($order->load('items.product'));

        return $order;
    }

    /**
     * Move an order to a new status, enforcing the state machine in OrderStatus.
     *
     * Cancellation additionally returns reserved stock to the catalogue. That
     * happens under the same transaction and the same row locks as a sale, so a
     * cancellation racing a checkout cannot double-credit inventory.
     */
    public function transitionTo(Order $order, OrderStatus $target): Order
    {
        $from = $order->status;

        if ($from === $target) {
            return $order;
        }

        if (! $from->canTransitionTo($target)) {
            throw new InvalidStatusTransitionException($from, $target);
        }

        DB::transaction(function () use ($order, $target, $from): void {
            if ($target === OrderStatus::Cancelled && $from->releasesStockOnCancel()) {
                $this->releaseStock($order);
            }

            $order->update([
                'status' => $target,
                'cancelled_at' => $target === OrderStatus::Cancelled ? now() : null,
            ]);
        });

        OrderStatusChanged::dispatch($order->refresh(), $from, $target);

        return $order;
    }

    /**
     * Customer-initiated cancellation. Stricter than the staff path: limited to
     * early statuses and to the configured time window, then delegated to the
     * same transition logic so exactly one code path cancels an order.
     */
    public function cancelAsCustomer(Order $order): Order
    {
        if (! $order->status->isCustomerCancellable()) {
            throw new OrderNotCancellableException(
                $order,
                "its status is [{$order->status->value}]"
            );
        }

        if (! $order->isWithinCancellationWindow()) {
            throw new OrderNotCancellableException(
                $order,
                sprintf(
                    'the %d minute cancellation window has closed',
                    (int) config('marketplace.orders.cancellation_window_minutes')
                )
            );
        }

        return $this->transitionTo($order, OrderStatus::Cancelled);
    }

    /** Return every line of a cancelled order to inventory. */
    private function releaseStock(Order $order): void
    {
        $order->loadMissing('items');

        $products = $this->inventory->lockProducts(
            $order->items->pluck('product_id')->all()
        );

        foreach ($order->items as $item) {
            $this->inventory->restock(
                $products[$item->product_id],
                $item->quantity,
                InventoryMovement::REASON_CANCELLATION,
                $order->id,
            );
        }
    }
}
