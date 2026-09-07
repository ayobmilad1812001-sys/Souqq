# 15 — Database Schema

Ten migrations. Every foreign key strategy and every index is a deliberate choice.

## Relationships

```
users ──┬──< products >──── categories
        │        │
        │        ├──< cart_items >──── carts ────┐
        │        ├──< order_items >──── orders ──┤
        │        ├──< reviews                    │
        │        └──< inventory_movements        │
        └───────────────────────────────────────┘
```

## Migrations

| File | Tables |
| --- | --- |
| `0001_01_01_000000_create_users_table` | `users`, `password_reset_tokens`, `sessions` |
| `0001_01_01_000001_create_cache_table` | `cache`, `cache_locks` |
| `0001_01_01_000002_create_jobs_table` | `jobs`, `job_batches`, `failed_jobs` |
| `0001_01_01_000003_create_personal_access_tokens_table` | `personal_access_tokens` |
| `2026_01_01_000001_create_categories_table` | `categories` |
| `2026_01_01_000002_create_products_table` | `products` |
| `2026_01_01_000003_create_carts_table` | `carts`, `cart_items` |
| `2026_01_01_000004_create_orders_table` | `orders`, `order_items` |
| `2026_01_01_000005_create_reviews_table` | `reviews` |
| `2026_01_01_000006_create_inventory_movements_table` | `inventory_movements` |

Every migration implements `down()` and drops tables in reverse dependency order,
so `migrate:rollback` works rather than failing on a foreign key.

---

## Tables

### `users`

```php
$table->string('email')->unique();
$table->enum('role', UserRole::values())->default(UserRole::Customer->value);
$table->index('role');
```

`unique` implies an index, so logins resolve with a single index seek. The `role`
index serves admin dashboards filtering by role.

The enum values come from `UserRole::values()` — the PHP enum and the column can
never drift apart.

### `categories`

```php
$table->string('slug')->unique();
```

Slugs are the public identifier in catalogue URLs and the `?category=` filter, so
they must be unique and indexed.

### `products`

```php
$table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
$table->foreignId('category_id')->constrained('categories')->restrictOnDelete();

$table->decimal('price', 10, 2);
$table->string('sku')->unique();
$table->unsignedInteger('stock_quantity')->default(0);
$table->boolean('is_active')->default(true);

$table->index(['category_id', 'is_active', 'price']);
$table->index(['seller_id', 'is_active']);
```

**`DECIMAL(10,2)`, never `FLOAT`/`DOUBLE`.** Binary floating point cannot store
most two-decimal values exactly and the error compounds across line items. See
[Money & Pricing](08-money-pricing.md).

**`unsignedInteger` on stock** — a negative stock quantity is meaningless, so the
column type forbids it in addition to the application logic.

**The composite index order matters.** `(category_id, is_active, price)` serves
the common listing query — filter by category, hide inactive, sort by price —
from one index. Equality predicates come first, the range/sort column last. Any
other ordering makes the index useless for that query.

### `carts` and `cart_items`

```php
$table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
```

```php
$table->foreignId('cart_id')->constrained()->cascadeOnDelete();
$table->foreignId('product_id')->constrained()->cascadeOnDelete();
$table->unsignedInteger('quantity');
$table->unique(['cart_id', 'product_id']);
```

**`carts.user_id` is unique** — one active cart per customer, enforced by the
database. Two concurrent "add to cart" requests cannot create two carts.

**`(cart_id, product_id)` is unique** — a product appears at most once per cart;
adding it again bumps the quantity.

**Carts cascade.** Unlike orders, a cart is disposable working state. Deleting a
user should take their cart with it.

### `orders` and `order_items`

```php
$table->foreignId('user_id')->constrained()->restrictOnDelete();
$table->enum('status', OrderStatus::values())->default(OrderStatus::Pending->value);

$table->decimal('subtotal', 10, 2);
$table->decimal('shipping_cost', 10, 2);
$table->decimal('total', 10, 2);
$table->timestamp('cancelled_at')->nullable();

$table->index(['user_id', 'created_at']);
$table->index(['status', 'created_at']);
```

```php
$table->foreignId('order_id')->constrained()->cascadeOnDelete();
$table->foreignId('product_id')->constrained()->restrictOnDelete();

$table->unsignedInteger('quantity');
$table->decimal('unit_price', 10, 2);    // frozen at purchase time
$table->decimal('subtotal', 10, 2);

$table->index('order_id');
$table->index('product_id');
```

**`unit_price` is deliberately denormalised.** It duplicates `products.price` at
the moment of sale, on purpose: an invoice must remain reproducible years later
after the seller has repriced, renamed or retired the product. This is one of the
few places where denormalisation is unambiguously correct.

**`orders.user_id` is `RESTRICT`, not `CASCADE`.** Orders are financial records.
A user row may not disappear from under them — deleting a customer must be a
deliberate process that deals with their order history first.

**`order_items.order_id` **is** `CASCADE`** — a line has no meaning without its
order, so deleting an order takes its lines.

**`order_items.product_id` is `RESTRICT`** — a sold product cannot be hard
deleted. `ProductService::delete()` deactivates it instead, turning a would-be
database error into intentional behaviour.

**The two composite indexes** serve "my orders, newest first" and the admin
status board respectively.

### `reviews`

```php
$table->unsignedTinyInteger('rating');
$table->unique(['user_id', 'product_id']);
$table->index(['product_id', 'rating']);
```

`unsignedTinyInteger` for a 1–5 value: one byte instead of four. Trivial on one
row, meaningful across millions.

The unique constraint enforces one review per customer per product. The composite
index acts as a covering index for `withAvg('reviews', 'rating')`.

### `inventory_movements`

```php
$table->foreignId('product_id')->constrained()->cascadeOnDelete();
$table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
$table->string('reason', 40);              // sale | cancellation | adjustment
$table->integer('quantity_delta');         // negative for a sale, positive for a restock
$table->unsignedInteger('resulting_quantity');

$table->index(['product_id', 'created_at']);
```

An append-only ledger. `order_id` is nullable with `nullOnDelete` because a manual
stock adjustment has no order, and a deleted order should not erase the history
of what it did to inventory.

`quantity_delta` is a **signed** integer — that is the point.

See [Concurrency & Inventory](07-concurrency-inventory.md).

---

## Foreign key strategy at a glance

| From → To | Strategy | Reason |
| --- | --- | --- |
| `products.seller_id` → `users` | RESTRICT | Deal with the catalogue before deleting a seller |
| `products.category_id` → `categories` | RESTRICT | A category with products cannot vanish |
| `carts.user_id` → `users` | CASCADE | Disposable working state |
| `cart_items.cart_id` → `carts` | CASCADE | Meaningless without the cart |
| `cart_items.product_id` → `products` | CASCADE | A removed product leaves carts |
| `orders.user_id` → `users` | RESTRICT | Financial record |
| `order_items.order_id` → `orders` | CASCADE | Meaningless without the order |
| `order_items.product_id` → `products` | RESTRICT | Invoices must stay resolvable |
| `reviews.*` | CASCADE | Reviews are disposable |
| `inventory_movements.order_id` → `orders` | SET NULL | Keep the history, drop the link |

The pattern: **CASCADE for disposable data, RESTRICT for records with financial
or legal meaning.**

---

## Index summary

| Table | Index | Serves |
| --- | --- | --- |
| `users` | `email` unique | Login |
| `users` | `role` | Admin user lists |
| `categories` | `slug` unique | Catalogue URLs, `?category=` |
| `products` | `sku` unique | Inventory lookup |
| `products` | `(category_id, is_active, price)` | The main listing query |
| `products` | `(seller_id, is_active)` | Seller dashboard |
| `carts` | `user_id` unique | One cart per customer |
| `cart_items` | `(cart_id, product_id)` unique | One line per product |
| `orders` | `(user_id, created_at)` | My orders, newest first |
| `orders` | `(status, created_at)` | Admin status board |
| `order_items` | `order_id` | Order detail |
| `order_items` | `product_id` | Seller sales across all orders |
| `reviews` | `(user_id, product_id)` unique | One review per person |
| `reviews` | `(product_id, rating)` | Average-rating aggregation |
| `inventory_movements` | `(product_id, created_at)` | Stock history |

---

## Seeding

```bash
php artisan migrate --seed
```

`DatabaseSeeder` creates 1 admin, 5 sellers, 21 customers, 6 categories and 75
products — including deliberately **out-of-stock** and **inactive** products, so
seeded data exercises the unhappy paths rather than only the happy one.

| Email | Role | Password |
| --- | --- | --- |
| `admin@libyamarket.test` | admin | `password` |
| `customer@libyamarket.test` | customer | `password` |

`UserFactory` hashes the shared password **once** and reuses the hash — bcrypt is
deliberately slow, and a suite creating hundreds of users would otherwise spend
most of its runtime hashing.
