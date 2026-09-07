# 06 — Orders & Atomic Checkout

The core of the system. Everything else exists to make this correct.

## Files

| File | Role |
| --- | --- |
| `app/Services/OrderService.php` | **The transaction** |
| `app/Enums/OrderStatus.php` | The state machine |
| `app/Models/Order.php` | Model, role scopes, cancellation window |
| `app/Models/OrderItem.php` | Frozen line snapshot |
| `app/Http/Controllers/Api/V1/OrderController.php` | HTTP layer |
| `app/Http/Requests/Order/UpdateOrderStatusRequest.php` | Status validation |
| `app/Policies/OrderPolicy.php` | Three views of one order |
| `tests/Feature/OrderPlacementTest.php` | 14 checkout tests |
| `tests/Feature/OrderLifecycleTest.php` | 18 lifecycle tests |

## Endpoints

| Method | Endpoint | Who | Throttle |
| --- | --- | --- | --- |
| `POST` | `/api/v1/orders` | customer | **10/min** |
| `GET` | `/api/v1/orders` | all (role-scoped) | 120/min |
| `GET` | `/api/v1/orders/{id}` | owner / seller / admin | 120/min |
| `PATCH` | `/api/v1/orders/{id}/status` | seller / admin | 120/min |
| `PATCH` | `/api/v1/orders/{id}/cancel` | owner | 120/min |

Checkout carries a tighter limit because each call opens a transaction and takes
row locks. No human needs to place ten orders a minute.

---

## The atomic transaction

```php
$order = DB::transaction(function () use ($user, $cart): Order {
    // 2. Lock every product row, in ascending id order, until commit.
    $products = $this->inventory->lockProducts($cart->items->pluck('product_id')->all());

    $lines = [];

    foreach ($cart->items as $item) {
        $product = $products[$item->product_id];

        // 3. Verify against the freshly locked row.
        $this->inventory->assertAvailable($product, $item->quantity);

        $lines[] = [
            'product'    => $product,
            'quantity'   => $item->quantity,
            'unit_price' => $product->priceAsMoney(),   // frozen here
        ];
    }

    // 4. All money computed once, from the frozen prices.
    $breakdown = $this->pricing->breakdown(...);

    $order = Order::query()->create([...]);

    // 5. Line items + stock draw-down under the same lock.
    foreach ($lines as $line) {
        $order->items()->create([...]);
        $this->inventory->decrement($line['product'], $line['quantity'], $order->id);
    }

    // 6. Cart emptied last, inside the transaction.
    $this->cartService->clear($cart);

    return $order;
});

// After commit only.
OrderCreated::dispatch($order->load('items.product'));
```

### The seven steps

1. Validate the cart is not empty
2. **Lock** every product row (`SELECT … FOR UPDATE`)
3. Verify stock and availability against the locked rows
4. Create the order
5. Create order items, freezing `unit_price`
6. Decrement stock
7. Clear the cart

If **any** step throws, the transaction rolls back completely: no orphaned order,
no phantom stock decrement, and the cart is left exactly as the shopper had it.

---

## Three ordering decisions that matter

### 1. The cart is cleared **inside** the transaction, **last**

If the cart were cleared before the order committed, a failure would leave the
shopper with no order *and* no basket — they would have to rebuild it from
memory. Clearing last, inside, means a failure returns them their basket intact.

Tested by `a_rejected_order_persists_nothing`, which asserts
`CartItem::count() === 2` after a rejected checkout.

### 2. `OrderCreated` is dispatched **after** commit, outside the closure

```php
});  // ← transaction commits here

OrderCreated::dispatch($order->load('items.product'));
```

Dispatching inside the transaction would let a listener email a customer about an
order that subsequently rolled back. Nothing is worse than a confirmation email
for an order that does not exist.

### 3. The queue connection sets `after_commit => true`

```php
'redis' => [
    // Jobs dispatched inside DB::transaction() are only pushed to Redis
    // once that transaction commits.
    'after_commit' => true,
],
```

`RecordInventoryMovementJob` **is** dispatched from inside the transaction. Without
this flag, a queue worker on another machine could pick the job up and query for
an order row that has not committed yet — a race that only shows up under load.

---

## Rollback, proven

The suite contains two different rollback tests because they prove different
things.

**`a_rejected_order_persists_nothing`** — a line is sold out. Availability for
every line is checked before any row is written, so nothing is written at all.

**`a_mid_transaction_failure_rolls_back_every_write`** — the real proof. A
failure is injected while the *second* order line is being written, after the
order row, the first line and the first stock decrement have already been issued:

```php
OrderItem::creating(function (): void {
    if (OrderItem::query()->exists()) {
        throw new RuntimeException('Simulated database failure mid-checkout.');
    }
});
```

and then asserts that every one of those writes disappeared:

```php
$this->assertSame(0,  Order::query()->count());
$this->assertSame(0,  OrderItem::query()->count());
$this->assertSame(10, $first->fresh()->stock_quantity);
$this->assertSame(0,  InventoryMovement::query()->count());
$this->assertSame(2,  CartItem::query()->count());
```

---

## The order state machine

```
pending ──> confirmed ──> processing ──> shipped ──> delivered
   │            │              │
   └────────────┴──────────────┴──────> cancelled
```

Encoded once, in `OrderStatus`:

```php
public function allowedTransitions(): array
{
    return match ($this) {
        self::Pending    => [self::Confirmed, self::Cancelled],
        self::Confirmed  => [self::Processing, self::Cancelled],
        self::Processing => [self::Shipped, self::Cancelled],
        self::Shipped    => [self::Delivered],
        self::Delivered, self::Cancelled => [],   // terminal
    };
}
```

Having exactly one answer to "can this order move from X to Y?" means one place
to change it and one place to unit test it. `OrderStatusTest` covers it with no
database at all.

### Validity of the value vs validity of the transition

```php
// Form Request — is 'shipped' a real status?
'status' => ['required', Rule::enum(OrderStatus::class)],
```

```php
// Service — may THIS order move there right now?
if (! $from->canTransitionTo($target)) {
    throw new InvalidStatusTransitionException($from, $target);
}
```

The first is a 422 (bad payload). The second is a 409 (valid payload, wrong
state). They are genuinely different errors and get different status codes.

### Clients are told the legal next moves

```json
{
  "status": "pending",
  "allowed_transitions": ["confirmed", "cancelled"]
}
```

Clients do not hard-code the state machine and drift out of sync with the server.

---

## Cancellation

Two paths, one implementation.

### Staff path — `PATCH /orders/{id}/status` with `cancelled`

Admin only. Not time-limited. A seller is explicitly forbidden, because an order
may contain another seller's goods.

### Customer path — `PATCH /orders/{id}/cancel`

Two independent gates:

```php
if (! $order->status->isCustomerCancellable()) {   // pending or confirmed only
    throw new OrderNotCancellableException($order, "its status is [{$order->status->value}]");
}

if (! $order->isWithinCancellationWindow()) {      // default 60 minutes
    throw new OrderNotCancellableException($order, sprintf(
        'the %d minute cancellation window has closed', $minutes
    ));
}

return $this->transitionTo($order, OrderStatus::Cancelled);
```

Note the last line: the customer path **delegates** to the same transition logic.
There is exactly one code path that cancels an order, so stock restoration can
never be accidentally skipped by one of them.

### Stock restoration

```php
if ($target === OrderStatus::Cancelled && $from->releasesStockOnCancel()) {
    $this->releaseStock($order);
}
```

```php
public function releasesStockOnCancel(): bool
{
    return in_array($this, [self::Pending, self::Confirmed, self::Processing], true);
}
```

Cancelling from `pending`, `confirmed` or `processing` returns the units to the
catalogue. A `shipped` order cannot be cancelled at all — the goods have
physically left the warehouse, and that is a **returns** problem, not a status
change.

Restoration takes the **same row locks** as a sale, inside the same transaction,
so a cancellation racing a checkout cannot double-credit inventory.

Tested by `cancelling_returns_stock_to_the_catalogue`.

---

## Price freezing

```php
$table->decimal('unit_price', 10, 2);   // captured at purchase time
$table->decimal('subtotal', 10, 2);
```

Deliberately denormalised. An invoice must remain reproducible years later, after
the seller has repriced, renamed, or retired the product. Foreign keys from
`order_items` use `RESTRICT`, not `CASCADE`, for the same reason.

Tested by `order_items_freeze_the_price_at_checkout`, which reprices the product
to `999.00` after checkout and asserts the order still says `50.00`.

---

## Role-scoped listing

```php
private function scopedQuery(User $user): Builder
{
    if ($user->isAdmin())  return Order::query();
    if ($user->isSeller()) return Order::query()->containingProductsOf($user->id);

    return Order::query()->where('user_id', $user->id);
}
```

Scoping happens **in the query**, not as a PHP filter after pagination. See
[Authorization & RBAC](02-authorization-rbac.md) for why that distinction matters.

---

## Tests

**`OrderPlacementTest`**

```
✓ a customer can place an order from their cart
✓ placing an order decrements stock
✓ placing an order empties the cart
✓ order items freeze the price at checkout
✓ an order spanning several products totals correctly
✓ an empty cart cannot be checked out
✓ an order is rejected when stock dropped after the item was carted
✓ a rejected order persists nothing
✓ a mid transaction failure rolls back every write
✓ an order is rejected if a carted product was deactivated
✓ two customers cannot both buy the last unit
✓ placing an order dispatches the order created event
✓ the confirmation email is queued rather than sent inline
✓ a guest cannot place an order
```

**`OrderLifecycleTest`**

```
✓ a customer sees only their own orders
✓ a seller sees only orders containing their products
✓ an admin sees every order
✓ orders can be filtered by status
✓ a customer cannot read someone elses order
✓ a seller can read an order containing their product
✓ a seller can advance an order through fulfilment
✓ the response advertises the legal next statuses
✓ an illegal transition is rejected
✓ an unknown status value is a validation error
✓ a seller cannot change the status of an unrelated order
✓ a customer cannot drive the fulfilment status
✓ a seller cannot cancel an order
✓ a customer can cancel their own pending order
✓ cancelling returns stock to the catalogue
✓ a shipped order cannot be cancelled by the customer
✓ cancellation is refused once the window has closed
✓ a customer cannot cancel another customers order
```
