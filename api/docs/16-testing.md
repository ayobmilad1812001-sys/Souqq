# 16 — Testing

**141 tests, 429 assertions, all passing.**

```
Tests:    141 passed (429 assertions)
Duration: 7.45s
```

## Running

```bash
php artisan test                      # everything
php artisan test --testsuite=Unit     # 26 tests, no database
php artisan test --testsuite=Feature  # 115 tests, HTTP + database
php artisan test --filter=OrderPlacementTest
```

Against real MySQL (exercises the locking primitives):

```bash
docker compose exec app php artisan test
```

---

## Configuration

```xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
<env name="CACHE_STORE" value="array"/>
<env name="QUEUE_CONNECTION" value="sync"/>
<env name="MAIL_MAILER" value="array"/>
<env name="BCRYPT_ROUNDS" value="4"/>
```

| Setting | Why |
| --- | --- |
| In-memory SQLite | The whole suite runs in 7 seconds. A slow suite does not get run. |
| `array` cache | Same `remember`/`forget`/`increment` semantics, no Redis server needed |
| `sync` queue | Job effects are directly assertable; `Bus::fake()` covers deferral |
| `array` mail | Nothing leaves the machine |
| `BCRYPT_ROUNDS=4` | Hashing is deliberately slow; 4 rounds is enough for a test |

`failOnWarning` and `failOnRisky` are enabled — a test that asserts nothing, or
triggers a deprecation, fails rather than passing quietly.

---

## Unit suite — 26 tests

Pure logic, no database, no HTTP.

### `MoneyTest` (13)

```
✓ it parses decimal strings exactly
✓ it rounds extra decimal places half up
✓ it handles negative amounts
✓ it rejects malformed input
✓ summing many small amounts stays exact
✓ multiplication by quantity is exact
✓ subtraction and comparison behave
✓ sum folds a collection
✓ it round trips through its string form (×5 data sets)
```

The one that justifies the class: `summing_many_small_amounts_stays_exact` adds
`0.10` a thousand times and asserts exactly `100.00`.

Extends plain `PHPUnit\Framework\TestCase`, not Laravel's — `Money` has no
framework dependency and the test proves it.

### `PricingServiceTest` (7)

```
✓ it multiplies a line exactly
✓ it sums lines without drift
✓ shipping is flat below the free threshold
✓ shipping is free at and above the threshold
✓ an empty basket is never charged shipping
✓ breakdown returns a consistent triple
✓ a large free shipping basket totals to its subtotal
```

### `OrderStatusTest` (6)

```
✓ it allows the forward fulfilment path
✓ it rejects skipping and reversing steps
✓ terminal states accept nothing
✓ an order may be cancelled only before dispatch
✓ only pre dispatch statuses return stock
✓ customers may only self cancel early orders
```

The entire order state machine, tested without touching a database — the payoff
for encoding it in an enum instead of scattering `if` statements across services.

---

## Feature suite — 115 tests

| File | Tests | Covers |
| --- | --- | --- |
| `AuthenticationTest` | 12 | Registration, hashing, login, escalation guard, per-token logout |
| `ProductCatalogTest` | 15 | Search, wildcard escaping, filters, sorting, pagination caps |
| `ProductManagementTest` | 14 | CRUD, validation, decimal guard, **seller isolation** |
| `CategoryManagementTest` | 10 | Admin-only taxonomy, slugs, delete protection |
| `CartManagementTest` | 14 | Cart creation, accumulation, stock guards, privacy |
| `OrderPlacementTest` | 14 | **Atomic checkout**, stock, price freezing, **rollback** |
| `OrderLifecycleTest` | 18 | Role scoping, transitions, cancellation window, restocking |
| `ProductReviewTest` | 9 | Verified-purchaser rule, ratings, ownership |
| `CatalogCacheTest` | 9 | Cache population and **invalidation** on every write path |

---

## Tests worth reading

### `a_mid_transaction_failure_rolls_back_every_write`

The strongest test in the suite. Injects a failure while the **second** order line
is being written — after the order row, the first line and the first stock
decrement have already been issued inside the transaction:

```php
OrderItem::creating(function (): void {
    if (OrderItem::query()->exists()) {
        throw new RuntimeException('Simulated database failure mid-checkout.');
    }
});
```

then asserts every one of those writes disappeared:

```php
$this->assertSame(0,  Order::query()->count());
$this->assertSame(0,  OrderItem::query()->count());
$this->assertSame(10, $first->fresh()->stock_quantity);
$this->assertSame(0,  InventoryMovement::query()->count());
$this->assertSame(2,  CartItem::query()->count());
```

There is a subtlety in it worth noting, and the code comments it:

```php
// PHPUnit's own failure exception extends RuntimeException, so calling
// fail() inside the try would be swallowed by the catch below.
$aborted = false;

try {
    app(OrderService::class)->place($this->customer);
} catch (RuntimeException) {
    $aborted = true;
}

$this->assertTrue($aborted, '...');
```

`PHPUnit\Framework\Exception` extends `RuntimeException`. A `$this->fail()` inside
that `try` would be caught by the `catch` and the test would pass while proving
nothing.

### `login_does_not_reveal_whether_an_account_exists`

```php
$this->assertSame(
    $known->json('errors.email'),
    $unknown->json('errors.email'),
    'Login must return an identical message for unknown users and wrong passwords.'
);
```

Asserts the two responses are byte-identical rather than merely both being 422.

### `the_confirmation_email_is_queued_rather_than_sent_inline`

```php
Bus::fake();
// ... place an order ...
Bus::assertDispatched(SendOrderConfirmationJob::class);
```

Asserts the **architecture**, not the outcome. An inline `Mail::send()` would
still deliver the email and still pass a naive test — but would fail this one.

### `updating_a_product_serves_the_new_price_immediately`

The cache regression. Reads at `100.00`, updates to `75.00`, reads again, asserts
`75.00`. Without observer-based invalidation this fails for ten minutes.

### `a_seller_cannot_assign_a_product_to_another_seller`

Posts `seller_id: 99` and asserts the product is owned by the **authenticated
user**, not by 99. Ownership comes from the token, never the payload.

---

## Design decisions in the suite

### Factories, never hardcoded data

```php
Product::factory()->priced('30.00')->withStock(5)->create();
Product::factory()->inactive()->create();
Product::factory()->outOfStock()->create();
User::factory()->seller()->create();
```

Named states make intent obvious at the call site. `priced('30.00')` passes a
**string**, keeping even test data out of float territory.

### `Model::shouldBeStrict()` is on in tests

```php
Model::shouldBeStrict(! $this->app->isProduction());
```

Lazy loading (the N+1 trap), assigning non-existent attributes, and reading
unselected columns all **throw** in the test environment. A forgotten eager load
fails the suite rather than shipping as a latent performance bug.

This shaped real code: `ProductResource` reads `reviews_avg_rating` through
`getAttributes()` precisely because strict mode forbids the magic accessor on an
attribute the listing query never selected.

### Descriptive test names as a specification

```
✓ a seller cannot update another sellers product
✓ deleting a sold product deactivates it instead
✓ cancellation is refused once the window has closed
```

The output of `php artisan test` reads as a behaviour specification. A reviewer
can audit the rules without opening a single source file.

### Both happy and unhappy paths

Roughly half the suite is failures: 403s, 422s, 409s, rollbacks, race conditions.
A suite that only tests success proves the code works when nothing goes wrong,
which is the least interesting case.

---

## Known limitation, stated honestly

The suite runs on SQLite, where `lockForUpdate()` is a **no-op**, and PHPUnit is
single-threaded. So `two_customers_cannot_both_buy_the_last_unit` proves the
*logic* — the second checkout reads the post-commit figure and refuses — but not
the *blocking behaviour* of `SELECT … FOR UPDATE` itself.

Verifying that requires two genuinely concurrent MySQL connections:

```bash
docker compose exec app php artisan test
```

This is documented in the README, in the test's own docblock, and here. Claiming
a lock test that does not test locking would be worse than not having one.
