# LibyaMarket API

A production-oriented e-commerce marketplace REST API built on Laravel 12, MySQL 8, Redis and Docker.

The point of this codebase is not CRUD. It is the four things that make a marketplace hard: **atomic checkout**, **concurrency control over inventory**, **exact monetary arithmetic**, and **cache invalidation you can trust**. Each is documented below next to the code that implements it.

---

## Contents

> **Per-feature documentation lives in [`docs/`](docs/README.md)** — one document
> for each of the 17 features, covering what it does, where the code is, the
> design decisions and why, and which tests prove it.
> Arabic setup guide: [`docs/setup-ar.md`](docs/setup-ar.md).


- [Quick start](#quick-start)
- [Architecture](#architecture)
- [The four hard problems](#the-four-hard-problems)
  - [1. Atomic order creation](#1-atomic-order-creation)
  - [2. Concurrency control and inventory consistency](#2-concurrency-control-and-inventory-consistency)
  - [3. Money and financial precision](#3-money-and-financial-precision)
  - [4. Caching and invalidation](#4-caching-and-invalidation)
- [Roles and authorization](#roles-and-authorization)
- [Database schema](#database-schema)
- [API reference](#api-reference)
- [Response format](#response-format)
- [Background jobs and events](#background-jobs-and-events)
- [Testing](#testing)
- [Configuration](#configuration)

---

## Quick start

### With Docker (recommended)

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

The API is then on `http://localhost:8000/api/v1`.

The stack is five containers, exactly as specified:

| Service | Image | Role |
| --- | --- | --- |
| `nginx` | nginx:1.27-alpine | HTTP entry point, forwards PHP to php-fpm |
| `app` | custom (`docker/Dockerfile`) | PHP 8.3-FPM running the application |
| `mysql` | mysql:8.4 | Primary datastore |
| `redis` | redis:7-alpine | Cache **and** queue broker |
| `queue-worker` | same image as `app` | Processes queued jobs |

`queue-worker` deliberately builds from the same Dockerfile as `app`, so the worker can never run a different revision of the code than the web tier.

### Without Docker

A `composer.phar` is bundled at the project root for machines without a global
Composer install — substitute `php composer.phar` for `composer` below.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
php artisan queue:work redis
```

Set `DB_HOST=127.0.0.1` and `REDIS_HOST=127.0.0.1` in `.env` when running outside Compose.

### Demo accounts

`php artisan migrate --seed` creates a demo marketplace. All accounts use the password `password`.

| Email | Role |
| --- | --- |
| `admin@libyamarket.test` | admin |
| `customer@libyamarket.test` | customer |

---

## Architecture

Request flow, and what each layer is *not* allowed to do:

```
HTTP request
   │
   ▼
ForceJsonResponse ──── every response is JSON, including errors
   │
   ▼
Route + throttle ───── per-user rate limits (api / auth / checkout)
   │
   ▼
Form Request ───────── validation only. No business rules.
   │
   ▼
Controller ─────────── HTTP concerns only. Typically 3-6 lines per action.
   │                   Calls authorize(), delegates, wraps in ApiResponse.
   ▼
Policy ─────────────── who may do this to this record (multi-tenant isolation)
   │
   ▼
Service ────────────── all business logic. Transactions live here.
   │                   OrderService, InventoryService, CartService,
   │                   PricingService, ProductService, CategoryService
   ▼
Model / Eloquent ───── persistence, relationships, query scopes
   │
   ▼
Observer ───────────── cache invalidation, on every write path
```

Two consequences worth calling out:

- **No controller contains a `try/catch`.** Domain failures throw typed exceptions (`InsufficientStockException`, `EmptyCartException`, `InvalidStatusTransitionException`, …) that abort the surrounding transaction and are rendered centrally in `bootstrap/app.php`. Each carries its own HTTP status and machine-readable context.
- **No service formats HTTP.** Services return models or throw; `App\Support\ApiResponse` owns the envelope.

### Directory map

| Path | Contents |
| --- | --- |
| `app/Enums` | `UserRole`, `OrderStatus` (the order state machine) |
| `app/Exceptions` | Domain exceptions, each with an HTTP status and context payload |
| `app/Http/Controllers/Api/V1` | Thin controllers |
| `app/Http/Requests` | Form Requests, grouped by resource |
| `app/Http/Resources` | API Resources |
| `app/Models` | Eloquent models and query scopes |
| `app/Observers` | Cache invalidation hooks |
| `app/Policies` | Authorization, including seller isolation |
| `app/Services` | Business logic |
| `app/Support` | `Money`, `ApiResponse`, `CacheKeys` |
| `app/Jobs`, `app/Events`, `app/Listeners` | Asynchronous side effects |

---

## The four hard problems

### 1. Atomic order creation

`POST /api/v1/orders` → `App\Services\OrderService::place()`.

The entire checkout is one `DB::transaction`:

1. Validate the cart is not empty.
2. **Lock** every product row in the cart (`SELECT … FOR UPDATE`).
3. Verify stock and product availability against the *locked* rows.
4. Create the order.
5. Create order items, freezing `unit_price` at checkout time.
6. Decrement stock.
7. Clear the cart.

If any step throws, the transaction rolls back completely: no orphaned order, no phantom stock decrement, and **the cart is left exactly as the shopper had it** so they can fix the problem and retry.

Two ordering decisions matter:

- **The cart is cleared inside the transaction**, last. A failure after that point still returns the shopper's basket.
- **`OrderCreated` is dispatched after commit**, outside the closure. No listener can ever email a customer about an order that later rolled back. The Redis queue connection additionally sets `after_commit => true`, so jobs dispatched from *within* the transaction (the inventory ledger writes) are only pushed once the data they describe is actually visible.

Covered by `tests/Feature/OrderPlacementTest.php`, including `a_mid_transaction_failure_rolls_back_every_write`, which injects a failure while the *second* order line is being written — after the order row, the first line and the first stock decrement have already been issued — and asserts every one of those writes disappears.

### 2. Concurrency control and inventory consistency

**The race being defended against** (the classic lost update / oversell):

```
stock_quantity = 1

T1: SELECT stock_quantity  -> 1
T2: SELECT stock_quantity  -> 1     ← T1 has not written yet
T1: UPDATE SET stock = 0
T2: UPDATE SET stock = 0
```

Two customers bought the same single unit. Stock never went negative, so **nothing in the data hints that anything went wrong** — you find out when the warehouse cannot ship.

**Mitigation: pessimistic locking.** `InventoryService::lockProducts()` issues `SELECT … FOR UPDATE` (Eloquent's `lockForUpdate()`) inside the checkout transaction. The lock is held until commit or rollback, so T2 blocks on its `SELECT` until T1 finishes, then re-reads the true remaining quantity and correctly fails.

**Why pessimistic rather than optimistic (version column + retry)?**

- Checkout contention on a hot SKU is bursty by nature — flash sales, product drops. Optimistic retries would thrash: most transactions would fail and replay the whole order build, amplifying load exactly when the system is busiest.
- The critical section is tiny (a handful of row reads and updates), so lock hold time is short and the throughput cost is acceptable.
- It composes naturally with the single order transaction the design already requires.

**Deadlock avoidance.** Products are always locked in ascending `id` order (`InventoryService::lockProducts()` sorts before querying). Two carts containing the same two SKUs in opposite order will queue rather than deadlock.

**Defence in depth.** `InventoryService::decrement()` does not write a value computed in PHP. It issues a conditional decrement:

```sql
UPDATE products SET stock_quantity = stock_quantity - ?
WHERE id = ? AND stock_quantity >= ?
```

If a future refactor ever loses the lock, this still cannot oversell — the statement affects zero rows and the service throws.

**A note on the test suite.** The suite runs on SQLite, where `lockForUpdate()` is a harmless no-op, so `two_customers_cannot_both_buy_the_last_unit` proves the *logic* (the second checkout reads the post-commit figure and refuses) rather than the locking primitive itself. Verifying true blocking behaviour requires two concurrent MySQL connections; run the suite against the Compose MySQL service to exercise that path.

### 3. Money and financial precision

**No float ever touches a monetary value.**

- MySQL columns are `DECIMAL(10,2)`.
- Eloquent casts are `decimal:2`, which surfaces values as **strings**. Casting to float here would undo the entire point of the column.
- Arithmetic goes through `App\Support\Money`, an immutable value object that stores an **integer number of minor units** and only renders a decimal string at the boundary.
- API responses emit prices as JSON strings (`"249.99"`, not `249.99`).

`Money::of('0.10')` summed a thousand times returns exactly `100.00`; the equivalent float loop does not. That case is a test (`MoneyTest::summing_many_small_amounts_stays_exact`).

Validation reinforces the column: `'price' => ['numeric', 'decimal:0,2', …]` rejects `10.999` outright instead of silently rounding it on insert.

`PricingService` holds the arithmetic — line subtotals, shipping (flat rate below a configurable free-delivery threshold), totals — with no database and no HTTP, so it is unit tested directly.

### 4. Caching and invalidation

Redis backs both the cache and the queue. `App\Support\CacheKeys` mints every key, because invalidation is only trustworthy if the code that writes a key and the code that forgets it agree on the exact string.

Two shapes of cached data need two different tools:

**Single-entity reads** (a product, the category list) have one deterministic key, so a write simply forgets it. Exact and immediate.

**Product listings** are cached per filter combination, so one product edit can invalidate an unbounded number of keys. Redis has no efficient wildcard delete, and cache tags are unavailable on the plain `redis` store. Listings therefore live in a **versioned namespace**:

```
products:index:v{N}:{md5 of filters}
```

A write increments `N`. Every previously cached listing becomes unreachable in O(1) and falls out on its own TTL.

The trade-off is that one product edit cold-starts every listing query. That is the right way round for a marketplace: **serving a sold-out or mispriced product is far more expensive than recomputing a listing.**

Invalidation is attached to **model observers**, not to services, so it runs for every write path — a seller update, an admin edit, the stock decrement inside checkout, a future artisan command. There is no code path that can leave a stale price in Redis.

Personalised listings (`?mine=1`, admin views) bypass the shared cache entirely rather than risk writing one seller's inventory into a key another user could read.

---

## Roles and authorization

Three roles (`App\Enums\UserRole`), enforced by Policies with a coarse `role:` middleware gate in front of whole route groups.

| | Customer | Seller | Admin |
| --- | --- | --- | --- |
| Browse / search catalogue | ✅ | ✅ | ✅ |
| Manage own cart | ✅ | ✅ | ✅ |
| Place orders, view own orders | ✅ | ✅ | ✅ |
| Cancel own order (within window) | ✅ | ✅ | ✅ |
| Review purchased products | ✅ | ✅ | ✅ |
| Create / edit / delete **own** products | ❌ | ✅ | ✅ |
| Edit **another seller's** products | ❌ | ❌ | ✅ |
| View orders containing own products | ❌ | ✅ | ✅ |
| Advance order fulfilment status | ❌ | ✅ | ✅ |
| Cancel any order | ❌ | ❌ | ✅ |
| Manage categories | ❌ | ❌ | ✅ |
| Platform-wide statistics | ❌ | ❌ | ✅ |

**Multi-tenant isolation** is the rule that matters, and it is expressed once, in `ProductPolicy`: a seller may only touch rows where `products.seller_id === $user->id`. Order listings are scoped **in the query** (`Order::scopeContainingProductsOf`), not filtered in PHP afterwards, so a seller cannot receive another seller's rows even in a paginated edge case.

Two deliberate asymmetries:

- **`CartItemPolicy` has no admin bypass.** Nobody, including staff, edits someone else's basket.
- **A seller cannot cancel an order**, only advance it. An order may contain another seller's goods; cancellation is a platform decision.

Self-registration accepts `customer` or `seller` only. Accepting `admin` would be a one-line privilege-escalation hole, and there is a test for it.

---

## Database schema

```
users ──┬──< products >──── categories
        │        │
        │        ├──< cart_items >──── carts ────┐
        │        ├──< order_items >──── orders ──┤
        │        ├──< reviews                    │
        │        └──< inventory_movements        │
        └───────────────────────────────────────┘
```

| Table | Notable columns | Indexes |
| --- | --- | --- |
| `users` | `role` enum | `email` unique, `role` |
| `categories` | `slug` | `slug` unique |
| `products` | `price DECIMAL(10,2)`, `stock_quantity`, `is_active` | `sku` unique, `(category_id, is_active, price)`, `(seller_id, is_active)` |
| `carts` | — | `user_id` **unique** |
| `cart_items` | `quantity` | `(cart_id, product_id)` unique |
| `orders` | `status` enum, `subtotal`/`shipping_cost`/`total` DECIMAL | `(user_id, created_at)`, `(status, created_at)` |
| `order_items` | `unit_price`, `subtotal` DECIMAL | `order_id`, `product_id` |
| `reviews` | `rating` | `(user_id, product_id)` unique, `(product_id, rating)` |
| `inventory_movements` | `quantity_delta`, `resulting_quantity` | `(product_id, created_at)` |

Design notes:

- **`carts.user_id` is unique.** One active cart per customer, enforced by the database rather than by application code, so two concurrent "add to cart" requests cannot create two carts.
- **`(cart_id, product_id)` is unique.** Adding a product twice accumulates the quantity instead of duplicating the line.
- **The composite index on products** is ordered `(category_id, is_active, price)` to serve the common listing query — filter by category, hide inactive, sort by price — from a single index.
- **`order_items` denormalises `unit_price`.** This is deliberate: an invoice must stay reproducible years later, after the seller has repriced, renamed or retired the product. Foreign keys from orders use `RESTRICT`, not `CASCADE`, for the same reason. `ProductService::delete()` therefore deactivates a product that has been sold rather than deleting it.
- **`inventory_movements`** is an append-only stock ledger, written asynchronously by a queued job. It keeps audit work out of the hot, lock-holding section of checkout and gives operations a way to reconcile `stock_quantity` after an incident.

---

## API reference

All routes are prefixed `/api/v1`. Authentication is a Sanctum bearer token: `Authorization: Bearer {token}`.

### Auth

| Method | Endpoint | Auth | Notes |
| --- | --- | --- | --- |
| `POST` | `/register` | — | `role` may be `customer` or `seller`. Throttled 6/min. |
| `POST` | `/login` | — | Returns a token. Throttled 6/min. |
| `POST` | `/logout` | ✅ | Revokes **only** the current token. |
| `GET` | `/user` | ✅ | Authenticated profile. |

### Categories

| Method | Endpoint | Auth |
| --- | --- | --- |
| `GET` | `/categories` | — |
| `GET` | `/categories/{id}` | — |
| `POST` | `/categories` | admin |
| `PATCH` | `/categories/{id}` | admin |
| `DELETE` | `/categories/{id}` | admin (409 if it still holds products) |

### Products

| Method | Endpoint | Auth |
| --- | --- | --- |
| `GET` | `/products` | — |
| `GET` | `/products/{id}` | — |
| `POST` | `/products` | seller, admin |
| `PATCH` | `/products/{id}` | owner or admin |
| `DELETE` | `/products/{id}` | owner or admin |

`GET /products` filters:

| Parameter | Example | Notes |
| --- | --- | --- |
| `search` | `search=wireless` | Matches name and description. LIKE wildcards in the term are escaped. |
| `category` | `category=3` or `category=books` | Accepts an id or a slug. |
| `min_price`, `max_price` | `min_price=20&max_price=100` | `max_price` must be ≥ `min_price`. |
| `in_stock` | `in_stock=1` | |
| `mine` | `mine=1` | Seller's own inventory, including inactive. |
| `sort` | `price_asc`, `price_desc`, `name_asc`, `name_desc`, `newest`, `oldest` | Whitelisted; anything else is a 422. |
| `per_page`, `page` | `per_page=20&page=2` | `per_page` capped at 100. |

`sort` is constrained in **both** the Form Request and the model scope. The request gives the client a helpful 422; the scope guarantees no user-controlled string can reach an `ORDER BY` clause.

### Cart

| Method | Endpoint |
| --- | --- |
| `GET` | `/cart` |
| `POST` | `/cart/items` |
| `PATCH` | `/cart/items/{id}` |
| `DELETE` | `/cart/items/{id}` |

Every cart endpoint returns the **whole cart** with recomputed totals, so a client never has to re-fetch after an edit.

### Orders

| Method | Endpoint | Notes |
| --- | --- | --- |
| `POST` | `/orders` | Checkout. Throttled 10/min. |
| `GET` | `/orders` | Scoped by role. Supports `?status=`. |
| `GET` | `/orders/{id}` | |
| `PATCH` | `/orders/{id}/status` | Seller/admin fulfilment transitions. |
| `PATCH` | `/orders/{id}/cancel` | Customer self-service, within the window. |

Order state machine (`App\Enums\OrderStatus`):

```
pending ──> confirmed ──> processing ──> shipped ──> delivered
   │            │              │
   └────────────┴──────────────┴──────> cancelled
```

`delivered` and `cancelled` are terminal. Cancelling from `pending`, `confirmed` or `processing` returns the reserved stock to the catalogue — under the same transaction and the same row locks as a sale, so a cancellation racing a checkout cannot double-credit inventory. Cancelling a `shipped` order is a returns problem, not a status change, and is refused.

Every order response includes `allowed_transitions`, so clients do not hard-code the state machine and drift out of sync with the server.

### Reviews and stats

| Method | Endpoint | Auth |
| --- | --- | --- |
| `GET` | `/products/{id}/reviews` | — |
| `POST` | `/products/{id}/reviews` | verified purchaser, not the seller |
| `DELETE` | `/reviews/{id}` | author or admin |
| `GET` | `/stats` | seller (own) / admin (platform) |

---

## Response format

**Success**

```json
{
    "success": true,
    "data": { },
    "message": "Operation successful."
}
```

Paginated responses add `meta` and `links` **alongside** `data`, not nested inside it, so clients read pagination from a stable location.

**Validation error — 422**

```json
{
    "success": false,
    "message": "Validation failed.",
    "errors": {
        "price": ["The price field must have 0-2 decimal places."]
    }
}
```

**Domain error — 409**

Domain exceptions carry machine-readable context, so a client can react rather than just display a string:

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

The `available` figure is read **while holding the row lock**, so it is the authoritative committed value at that instant — not a stale number the shopper saw minutes ago.

Status codes: `200` OK · `201` created · `401` unauthenticated · `403` unauthorized · `404` not found · `409` conflict (stock, illegal transition, non-empty category) · `422` validation or business-rule violation · `429` throttled.

---

## Background jobs and events

Queue connection is Redis. Nothing that can be deferred runs in the request path.

**Events → listeners**

| Event | Listeners |
| --- | --- |
| `OrderCreated` | `SendOrderConfirmation`, `NotifySellersOfNewOrder` (queued) |
| `OrderStatusChanged` | `LogOrderStatusChange` (queued) |

`OrderStatusChanged` carries both `from` and `to`, so a listener can react to a specific edge (`shipped → delivered`) rather than re-deriving it.

**Jobs**

| Job | Why it is queued |
| --- | --- |
| `SendOrderConfirmationJob` | An SMTP round trip would add hundreds of milliseconds to the most conversion-sensitive endpoint in the API, and a mail outage would surface to shoppers as a failed checkout for an order that actually succeeded. Retries `[10s, 60s, 300s]`. |
| `RecordInventoryMovementJob` | Ledger writes are audit data. Writing them inline would extend the row-lock hold time inside checkout, where every millisecond is contended. |

Failed jobs are persisted to **MySQL**, not Redis, so flushing the cache can never lose the failure audit trail.

Adding another side effect to checkout means adding a listener, not editing `OrderService`.

---

## Testing

```bash
php artisan test                      # everything
php artisan test --testsuite=Unit     # pure logic, no database
php artisan test --testsuite=Feature  # HTTP + database
```

Feature tests run against an in-memory SQLite database (`phpunit.xml`) for speed. To exercise the real locking primitives, point the suite at the Compose MySQL service instead:

```bash
docker compose exec app php artisan test
```

| Suite | File | Covers |
| --- | --- | --- |
| Unit | `MoneyTest` | Parsing, half-up rounding, exact summation, negative amounts, malformed input |
| Unit | `PricingServiceTest` | Line subtotals, free-shipping threshold, drift-free totals |
| Unit | `OrderStatusTest` | The state machine, terminal states, stock-release rules |
| Feature | `AuthenticationTest` | Registration, hashing, login, admin-role escalation guard, per-token logout, account enumeration |
| Feature | `ProductCatalogTest` | Search, LIKE-wildcard escaping, category/price filters, sorting, pagination caps, visibility of inactive products |
| Feature | `ProductManagementTest` | CRUD, validation, decimal-precision guard, **seller isolation**, sold-product deactivation |
| Feature | `CategoryManagementTest` | Admin-only taxonomy, slug uniqueness, non-empty-category refusal |
| Feature | `CartManagementTest` | Cart creation, quantity accumulation, stock guards, cross-tenant protection |
| Feature | `OrderPlacementTest` | Atomic checkout, stock decrement, price freezing, empty cart, oversell rejection, **full rollback**, queued confirmation |
| Feature | `OrderLifecycleTest` | Role-scoped listings, legal/illegal transitions, cancellation window, stock restoration |
| Feature | `ProductReviewTest` | Verified-purchaser rule, self-review block, average rating, ownership |
| Feature | `CatalogCacheTest` | Cache population and **invalidation** on every write path |

---

## Configuration

Domain rules live in `config/marketplace.php` so operations staff can tune them without a code change. Monetary defaults are strings, never floats.

| Env var | Default | Meaning |
| --- | --- | --- |
| `SHIPPING_FLAT_RATE` | `15.00` | Flat fee charged per order |
| `SHIPPING_FREE_THRESHOLD` | `500.00` | Subtotal at which shipping becomes free |
| `ORDER_CANCELLATION_WINDOW_MINUTES` | `60` | Customer self-cancellation window |
| `CART_MAX_QUANTITY_PER_ITEM` | `100` | Per-line quantity cap |
| `PRODUCT_CACHE_TTL` | `600` | Catalogue cache TTL in seconds |

Rate limits (`AppServiceProvider::configureRateLimiting`): `api` 120/min, `auth` 6/min keyed on email+IP, `checkout` 10/min.

`Model::shouldBeStrict()` is enabled outside production, so lazy loading (the N+1 trap), assigning non-existent attributes, and reading unselected columns all fail loudly in development and CI rather than shipping as latent bugs.
