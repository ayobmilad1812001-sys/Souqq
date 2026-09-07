# 07 — Concurrency Control & Inventory

The hardest problem in the system, and the one a naive implementation gets silently wrong.

## Files

| File | Role |
| --- | --- |
| `app/Services/InventoryService.php` | **Every** write to `stock_quantity` |
| `app/Models/InventoryMovement.php` | Append-only stock ledger |
| `app/Jobs/RecordInventoryMovementJob.php` | Async ledger writes |
| `app/Exceptions/InsufficientStockException.php` | 409 with authoritative figures |
| `database/migrations/..._create_inventory_movements_table.php` | Ledger schema |

---

## The race being defended against

The classic **lost update** / oversell:

```
stock_quantity = 1

T1: SELECT stock_quantity  -> 1
T2: SELECT stock_quantity  -> 1      ← T1 has not written yet
T1: UPDATE SET stock = 0
T2: UPDATE SET stock = 0
```

Two customers bought the same single unit.

**The dangerous part:** stock never went negative. There is no constraint
violation, no error log, no alert. The data looks perfectly consistent. You find
out when the warehouse cannot ship the second order — days later, at the cost of
a refund and a customer.

This is why the problem is worth real engineering rather than an `if` statement.

---

## The mitigation: pessimistic locking

```php
public function lockProducts(array $productIds): Collection
{
    $ids = array_values(array_unique($productIds));
    sort($ids);   // deterministic global lock ordering

    return Product::query()
        ->whereIn('id', $ids)
        ->orderBy('id')
        ->lockForUpdate()      // SELECT ... FOR UPDATE
        ->get()
        ->keyBy('id');
}
```

`lockForUpdate()` emits `SELECT … FOR UPDATE`. The lock is taken **inside** the
checkout transaction and held until commit or rollback. T2 now **blocks** on its
SELECT until T1 finishes, then re-reads the true remaining quantity (0) and
correctly fails with a 409.

> **Critical:** this must be called inside a transaction. Outside one, MySQL
> commits immediately and releases the lock — the guarantee evaporates silently.
> The docblock on the method says exactly this.

---

## Why pessimistic and not optimistic?

The alternative is a `version` column with compare-and-swap plus retry. It was
rejected for three reasons:

**1. Contention here is bursty and high.**
Flash sales and product drops mean hundreds of shoppers hitting one SKU in the
same second. Optimistic retries would thrash: most transactions fail their CAS
and replay the *entire* order build — re-reading the cart, recomputing money,
re-inserting lines. Load amplifies exactly when the system is already busiest.
That is a failure mode that feeds itself.

**2. The critical section is tiny.**
A handful of row reads and updates. Lock hold time is measured in single-digit
milliseconds, so the throughput cost of blocking is acceptable.

**3. It composes with the transaction we already need.**
The PRD requires the whole checkout to be atomic. A lock taken inside that
transaction is free structurally — no extra column, no retry loop, no
partially-applied state to reason about.

Optimistic locking would be the better choice for a low-contention, long-running
edit (a seller editing a product description for five minutes). It is the wrong
choice for a two-millisecond checkout on a hot SKU.

---

## Deadlock avoidance

```php
sort($ids);   // ascending id order, always
```

Consider two carts:

```
Cart A: [product 7, product 3]
Cart B: [product 3, product 7]
```

Without ordering, A locks 7 and waits for 3; B locks 3 and waits for 7. Classic
deadlock. MySQL detects it and kills one transaction — a random checkout failure
under load that is miserable to reproduce.

Sorting means **every** transaction acquires locks in the same global order, so
they queue instead of deadlocking. This is a one-line fix for a class of bug that
otherwise appears only in production.

---

## Defence in depth: the conditional decrement

The decrement does **not** write a value computed in PHP:

```php
$affected = Product::query()
    ->whereKey($product->getKey())
    ->where('stock_quantity', '>=', $quantity)     // ← the guard
    ->update([
        'stock_quantity' => DB::raw("stock_quantity - {$quantity}"),
        'updated_at' => now(),
    ]);

if ($affected === 0) {
    $product->refresh();
    throw new InsufficientStockException($product, $quantity, $product->stock_quantity);
}
```

Two properties:

- **`stock_quantity - {$quantity}`** is evaluated by the database, not by PHP.
  Even without a lock this is atomic at the row level.
- **`WHERE stock_quantity >= ?`** means that if a future refactor ever loses the
  lock, the statement simply affects zero rows and the service fails loudly.
  Overselling becomes structurally impossible rather than merely unlikely.

Belt **and** braces. The lock is the design; this is the safety net.

---

## Authoritative error reporting

```php
throw new InsufficientStockException($product, $quantity, $product->stock_quantity);
```

```json
{
  "success": false,
  "message": "Insufficient stock for [Mechanical Keyboard]. Requested 5, only 1 available.",
  "errors": {
    "product": { "id": 12, "name": "Mechanical Keyboard", "sku": "MECH-KB-01" },
    "requested": 5,
    "available": 1
  }
}
```

Because the exception is raised **while holding the row lock**, the `available`
figure is the authoritative committed value at that instant — not a stale number
the shopper saw on the listing page ten minutes ago. A client can act on it:
"only 1 left, buy it?" rather than just printing a string.

Status is **409 Conflict**, not 422: the request was well formed, it conflicts
with current state, and a retry with a smaller quantity may succeed.

---

## Restocking on cancellation

```php
public function restock(Product $product, int $quantity, string $reason, ?int $orderId = null): void
{
    Product::query()
        ->whereKey($product->getKey())
        ->update(['stock_quantity' => DB::raw("stock_quantity + {$quantity}"), ...]);

    $this->recordMovement($product, $quantity, $reason, $orderId);
}
```

Called from `OrderService::releaseStock()`, which takes the **same row locks**
inside the **same transaction** as a sale. A cancellation racing a checkout
therefore queues behind it and cannot double-credit inventory.

---

## The inventory ledger

```php
Schema::create('inventory_movements', function (Blueprint $table): void {
    $table->foreignId('product_id')->constrained()->cascadeOnDelete();
    $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
    $table->string('reason', 40);              // sale | cancellation | adjustment
    $table->integer('quantity_delta');         // negative for a sale
    $table->unsignedInteger('resulting_quantity');
    $table->timestamps();

    $table->index(['product_id', 'created_at']);
});
```

An **append-only** record of every stock change. Rows are written, never updated.

**Why it exists:** `stock_quantity` is a single mutable number. When it is wrong —
and eventually it will be, through a bug, a manual SQL fix, or a partial outage —
there is no way to reconstruct what happened. The ledger gives operations a
replayable history to reconcile against.

**Why it is queued:**

```php
private function recordMovement(Product $product, int $delta, string $reason, ?int $orderId): void
{
    RecordInventoryMovementJob::dispatch(...);
}
```

Ledger writes are audit data, not part of the correctness of the sale. Writing
them inline would extend the **row-lock hold time** inside checkout, where every
millisecond is contended by every other shopper waiting on that SKU. Pushing them
to the queue keeps the critical section as short as possible.

The `after_commit => true` queue setting ensures those jobs are only pushed once
the transaction they describe has actually committed.

---

## What the test suite does and does not prove

**Proven by `two_customers_cannot_both_buy_the_last_unit`:**
the second checkout reads the post-commit stock figure and refuses. Stock never
goes negative. Exactly one order exists.

**Not proven by the suite as configured:** the *blocking behaviour* of
`SELECT … FOR UPDATE` itself. The suite runs on in-memory SQLite, where
`lockForUpdate()` is a harmless no-op, and PHPUnit is single-threaded — two
genuinely concurrent connections cannot be produced in-process.

To exercise the real primitive, run the suite against the Compose MySQL service:

```bash
docker compose exec app php artisan test
```

This limitation is documented in the README and in the test's own docblock rather
than glossed over. Claiming a lock test that does not test locking would be
worse than not having one.

---

## Summary of guarantees

| Guarantee | Mechanism |
| --- | --- |
| No two orders take the same unit | `SELECT … FOR UPDATE` held to commit |
| No deadlocks between overlapping carts | Ascending id lock ordering |
| Stock cannot go negative even without a lock | `WHERE stock_quantity >= ?` |
| Error reports the true remaining quantity | Exception raised under lock |
| Cancellation cannot double-credit | Same locks, same transaction |
| Stock history is reconstructable | Append-only ledger |
| Audit work never slows checkout | Ledger writes queued |
