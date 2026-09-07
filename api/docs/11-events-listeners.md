# 11 — Events & Listeners

Domain events decouple side effects from the transaction that caused them.

## Files

| File | Role |
| --- | --- |
| `app/Events/OrderCreated.php` | Fired after checkout commits |
| `app/Events/OrderStatusChanged.php` | Fired on every lifecycle move |
| `app/Listeners/SendOrderConfirmation.php` | Enqueues the email |
| `app/Listeners/NotifySellersOfNewOrder.php` | Fans out to sellers (queued) |
| `app/Listeners/LogOrderStatusChange.php` | Audit trail (queued) |
| `app/Providers/AppServiceProvider.php` | Wiring |

---

## Wiring

```php
private function configureEvents(): void
{
    Event::listen(OrderCreated::class, SendOrderConfirmation::class);
    Event::listen(OrderCreated::class, NotifySellersOfNewOrder::class);
    Event::listen(OrderStatusChanged::class, LogOrderStatusChange::class);
}
```

Explicit registration rather than auto-discovery: the wiring is readable in one
place, and adding a listener is a visible diff rather than a filename convention.

---

## `OrderCreated`

```php
final class OrderCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Order $order) {}
}
```

Dispatched **after** the checkout transaction commits:

```php
});  // transaction commits

OrderCreated::dispatch($order->load('items.product'));
```

### Why this matters

Everything that is not part of *making the sale* hangs off this event —
confirmation email, seller notification, analytics, loyalty points, fraud
scoring. `OrderService::place()` knows nothing about any of them.

Adding another side effect later means **adding a listener, not editing
`OrderService`**. That is the whole point: the most safety-critical method in the
codebase — the one holding row locks inside a transaction — never grows.

Dispatching after commit also guarantees no listener can email a customer about
an order that subsequently rolled back.

---

## `OrderStatusChanged`

```php
final class OrderStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly OrderStatus $from,
        public readonly OrderStatus $to,
    ) {}

    public function isShipment(): bool    { return $this->to === OrderStatus::Shipped; }
    public function isCancellation(): bool { return $this->to === OrderStatus::Cancelled; }
}
```

### Carrying both ends of the transition

A weaker design would carry only the order and let listeners read
`$order->status`. That loses the `from` value, so a listener cannot distinguish
"just shipped" from "was already shipped" — and re-deriving it means querying an
audit table or guessing.

Carrying `from` **and** `to` lets a listener react to a specific **edge**:

```php
if ($event->from === OrderStatus::Processing && $event->to === OrderStatus::Shipped) {
    // send tracking number
}
```

The `isShipment()` / `isCancellation()` helpers cover the common cases without
each listener re-implementing the comparison.

This single event replaces what would otherwise be `OrderConfirmed`,
`OrderProcessing`, `OrderShipped`, `OrderDelivered` and `OrderCancelled` — five
near-identical classes and five registration lines.

---

## Listener 1 — `SendOrderConfirmation` (synchronous)

```php
final class SendOrderConfirmation
{
    public function handle(OrderCreated $event): void
    {
        SendOrderConfirmationJob::dispatch($event->order->id);
    }
}
```

Deliberately **not** queued. All it does is push a job — a single Redis `LPUSH`,
microseconds. Queuing the enqueue would be pure overhead.

The split matters for failure semantics: if the broker is unreachable, the
failure surfaces here as a **failed job dispatch**, not as a failed checkout. The
order is already committed and safe.

---

## Listener 2 — `NotifySellersOfNewOrder` (queued)

```php
final class NotifySellersOfNewOrder implements ShouldQueue
{
    public function handle(OrderCreated $event): void
    {
        $event->order->loadMissing('items.product');

        $sellerIds = $event->order->items->pluck('product.seller_id')->unique()->values();

        foreach ($sellerIds as $sellerId) {
            Log::info('New order awaiting fulfilment.', [
                'order_id'  => $event->order->id,
                'seller_id' => $sellerId,
            ]);
        }
    }
}
```

Queued via `implements ShouldQueue`, because one order can span many sellers and
none of that fan-out belongs in the checkout request.

`unique()` matters: an order containing three products from the same seller
should produce **one** notification, not three.

The `Log::info` stands in for the real channel a production deployment would wire
up here — push notification, SMS, seller webhook. The structure is correct; only
the transport is a placeholder, and it is labelled as such in the code.

---

## Listener 3 — `LogOrderStatusChange` (queued)

```php
final class LogOrderStatusChange implements ShouldQueue
{
    public function handle(OrderStatusChanged $event): void
    {
        Log::info('Order status changed.', [
            'order_id' => $event->order->id,
            'from'     => $event->from->value,
            'to'       => $event->to->value,
        ]);
    }
}
```

An audit trail of every lifecycle change, kept off the request thread. Structured
context (not an interpolated string) so a log aggregator can filter on
`from`/`to` without regex.

---

## Sync vs queued: the rule

| Listener does | Make it |
| --- | --- |
| Push a job, flip an in-memory flag | **synchronous** |
| Any I/O — HTTP, email, SMS, heavy DB work | **queued** |

Queuing everything adds latency and failure surface for no gain. Queuing nothing
puts network calls in the request path. The split above is the line.

---

## Event flow at a glance

```
POST /orders
     │
     ▼
OrderService::place()
     │
     ├── DB::transaction { lock → verify → create → decrement → clear cart }
     │        │
     │        └── RecordInventoryMovementJob  (held until commit by after_commit)
     │
     │  ── COMMIT ──
     │
     └── OrderCreated::dispatch()
              │
              ├── SendOrderConfirmation   (sync)  → SendOrderConfirmationJob (queued)
              └── NotifySellersOfNewOrder (queued)

PATCH /orders/{id}/status
     │
     ▼
OrderService::transitionTo()
     │
     ├── DB::transaction { restock if cancelling; update status }
     │
     │  ── COMMIT ──
     │
     └── OrderStatusChanged::dispatch(order, from, to)
              │
              └── LogOrderStatusChange (queued)
```

---

## Testing

```php
#[Test]
public function placing_an_order_dispatches_the_order_created_event(): void
{
    Event::fake([OrderCreated::class]);

    // ... place an order ...

    Event::assertDispatched(OrderCreated::class);
}
```

`Event::fake([OrderCreated::class])` fakes **only** that event. A bare
`Event::fake()` would also swallow Eloquent model events, which would silently
disable the cache-invalidation observers and make unrelated assertions lie.
