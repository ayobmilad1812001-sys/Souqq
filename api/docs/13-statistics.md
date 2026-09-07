# 13 — Statistics

One endpoint, two answers: sellers see their own numbers, admins see the platform.

## Files

| File | Role |
| --- | --- |
| `app/Http/Controllers/Api/V1/StatsController.php` | Single-action controller |
| `routes/api.php` | Behind `role:seller,admin` |

## Endpoint

| Method | Endpoint | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/stats` | seller, admin |

### Seller response

```json
{
  "success": true,
  "data": {
    "scope": "seller",
    "products_total": 15,
    "products_active": 12,
    "out_of_stock": 2,
    "units_sold": 143,
    "gross_revenue": "18420.50"
  },
  "message": "Statistics retrieved."
}
```

### Admin response

```json
{
  "success": true,
  "data": {
    "scope": "platform",
    "users_total": 27,
    "sellers_total": 5,
    "products_total": 75,
    "orders_total": 312,
    "orders_by_status": { "pending": 14, "shipped": 40, "delivered": 251, "cancelled": 7 },
    "gross_revenue": "412903.75"
  },
  "message": "Statistics retrieved."
}
```

The `scope` field tells the client which shape it received, so a shared dashboard
component can branch without inspecting keys.

---

## Design decisions

### 1. Every figure is a single aggregate query

```php
'units_sold' => (int) OrderItem::query()
    ->whereHas('product', fn ($query) => $query->where('seller_id', $user->id))
    ->whereHas('order', fn ($query) => $query->where('status', '!=', OrderStatus::Cancelled))
    ->sum('quantity'),
```

The database does the counting. Nothing is loaded into PHP and summed.

This is the difference between a **constant-cost** endpoint and one that degrades
as the marketplace grows. A seller with 50 orders and a seller with 500,000
orders hit the same query plan; only the row scan differs, and the indexes cover
it. Loading order items into memory to `array_sum()` them would work fine in
development and fall over at exactly the moment the platform succeeds.

### 2. Cancelled orders are excluded from revenue

```php
->whereHas('order', fn ($query) => $query->where('status', '!=', OrderStatus::Cancelled))
```

A cancelled order is not revenue. Including it would inflate every seller's
numbers and make the platform total meaningless. Cancelled orders are kept in the
table (they are history), so the filter has to be explicit.

### 3. Revenue is re-parsed through `Money`, never trusted as a float

```php
$revenue = OrderItem::query()->...->sum('subtotal');

return [
    'gross_revenue' => Money::of((string) ($revenue ?: '0.00'))->toDecimalString(),
];
```

`sum()` on a `DECIMAL` column returns a string from MySQL but PHP can widen it.
Casting to string and re-parsing through `Money` keeps the value in the exact
path all the way to the JSON response, and `?: '0.00'` handles the `null` a
seller with no sales gets.

See [Money & Pricing](08-money-pricing.md).

### 4. Seller isolation applies here too

```php
Product::query()->where('seller_id', $user->id)->count()
```

Every seller figure is filtered by `seller_id`. A seller must not learn how many
products a competitor lists or how much they sold.

Tested by `a_seller_sees_only_their_own_sales_statistics`, which creates 3
products for the seller and 5 for another, then asserts `products_total` is 3.

### 5. `orders_by_status` uses `GROUP BY`, not six counts

```php
'orders_by_status' => Order::query()
    ->selectRaw('status, count(*) as aggregate')
    ->groupBy('status')
    ->pluck('aggregate', 'status'),
```

One query returns the whole breakdown as a keyed map. The alternative — a
separate `count()` per status — is six round trips for the same information.

### 6. A single-action controller

```php
final class StatsController extends Controller
{
    public function __invoke(Request $request): JsonResponse { ... }
}
```

```php
Route::get('stats', StatsController::class);
```

The endpoint does one thing. An `__invoke` controller says that in the type
system rather than parking a lone `index()` method inside an otherwise empty
resource controller.

---

## Authorization

Two layers, as everywhere else:

```php
Route::middleware('role:seller,admin')->group(function (): void {
    Route::get('stats', StatsController::class);
});
```

The middleware blocks customers with a 403 before any query runs. Inside, the
controller branches on `$user->isAdmin()` to decide which of the two shapes to
build. See [Authorization & RBAC](02-authorization-rbac.md).

---

## Not implemented (deliberately)

Things a production dashboard would add, left out because the PRD did not ask and
guessing at them would be scope creep:

- **Time ranges** (`?from=&to=`) — every figure here is all-time.
- **Caching** — these are low-volume dashboard reads; caching them would add
  invalidation complexity for no measurable win until it is proven needed.
- **Top-selling products / revenue over time** — reporting features, better
  served by a read replica or a warehouse than by the transactional database.

Each is a small addition on top of the existing structure if requirements grow.
