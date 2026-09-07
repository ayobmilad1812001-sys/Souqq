# 05 — Shopping Cart

One active cart per customer, enforced by the database rather than by hope.

## Files

| File | Role |
| --- | --- |
| `app/Models/Cart.php` | Model, live subtotal |
| `app/Models/CartItem.php` | Line model, live line total |
| `app/Services/CartService.php` | All cart mutations |
| `app/Http/Controllers/Api/V1/CartController.php` | HTTP layer |
| `app/Http/Requests/Cart/StoreCartItemRequest.php` | Add rules |
| `app/Http/Requests/Cart/UpdateCartItemRequest.php` | Update rules |
| `app/Http/Resources/CartResource.php` | Cart output with totals |
| `app/Policies/CartItemPolicy.php` | Cart privacy |
| `tests/Feature/CartManagementTest.php` | 14 tests |

## Endpoints

| Method | Endpoint | Body |
| --- | --- | --- |
| `GET` | `/api/v1/cart` | — |
| `POST` | `/api/v1/cart/items` | `{"product_id": 5, "quantity": 2}` |
| `PATCH` | `/api/v1/cart/items/{id}` | `{"quantity": 4}` |
| `DELETE` | `/api/v1/cart/items/{id}` | — |

All four return the **whole cart** with recomputed totals:

```json
{
  "success": true,
  "data": {
    "id": 1,
    "items": [
      {
        "id": 7,
        "quantity": 2,
        "unit_price": "25.00",
        "line_total": "50.00",
        "product": { "id": 5, "name": "Mechanical Keyboard", "...": "..." }
      }
    ],
    "items_count": 1,
    "total_quantity": 2,
    "subtotal": "50.00",
    "estimated_shipping": "15.00",
    "estimated_total": "65.00"
  },
  "message": "Item added to cart."
}
```

## Design decisions

### 1. One cart per customer — enforced by a unique index

```php
$table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
```

Two concurrent "add to cart" requests from the same user (a double-tapped
button, a retried request) cannot create two carts. The database refuses.

The service handles the resulting race explicitly rather than pretending it
cannot happen:

```php
try {
    $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
} catch (UniqueConstraintViolationException) {
    $cart = Cart::query()->where('user_id', $user->id)->firstOrFail();
}
```

`firstOrCreate` is **not** atomic — it is a SELECT followed by an INSERT. Under
concurrency the second request loses the race, hits the unique index, and the
catch re-reads the cart the winner created.

Tested by `repeated_access_never_creates_a_second_cart`.

### 2. Adding the same product twice accumulates, never duplicates

```php
$table->unique(['cart_id', 'product_id']);
```

```php
$existing = $cart->items->firstWhere('product_id', $product->id);
$desired = ($existing?->quantity ?? 0) + $quantity;

CartItem::query()->updateOrCreate(
    ['cart_id' => $cart->id, 'product_id' => $product->id],
    ['quantity' => $desired],
);
```

Adding 2 then 3 gives **one line of 5**, not two lines. The unique index is the
guarantee; `updateOrCreate` is how the application cooperates with it.

Tested by `adding_the_same_product_twice_increases_the_quantity`.

### 3. Stock is checked against the **accumulated** quantity

```php
$desired = ($existing?->quantity ?? 0) + $quantity;
$this->assertPurchasable($product, $desired);
```

Checking only the incoming `quantity` would let a shopper add 4, then 4 more, to
a product with 5 in stock. The check must be against the resulting total.

Tested by `accumulated_quantity_is_checked_against_stock`.

### 4. The stock check here is **advisory**, not authoritative

This is the single most important thing to understand about this feature.

```php
// Stock is validated here for fast feedback, but this check is advisory
// only: the authoritative check happens under a row lock at checkout, since
// stock can change between browsing and paying.
```

A cart can sit for hours. Whatever stock figure it validated against is stale
almost immediately. The **real** guarantee is the `SELECT … FOR UPDATE` inside
the checkout transaction — see [Concurrency & Inventory](07-concurrency-inventory.md).

The cart check exists purely so a shopper learns about a problem at the moment
they add an item, not at the moment they try to pay.

### 5. Cart lines are priced **live**, order lines are **frozen**

```php
public function lineTotal(): Money
{
    return $this->product->priceAsMoney()->times($this->quantity);
}
```

A cart always reflects today's price. The moment an order is placed, the price is
copied into `order_items.unit_price` and never changes again. This is the correct
split: a shopper pays the price shown when they *check out*, and an invoice
stays reproducible forever after.

### 6. Every endpoint returns the whole cart

A client that adds an item needs the new subtotal, the new shipping estimate and
the new item count. Returning only the mutated line would force a second request
on every single interaction. One round trip, complete state.

### 7. Estimated shipping is shown before checkout

```php
$pricing = app(PricingService::class);
$shipping = $pricing->shippingCost($subtotal);
```

This lets the cart screen display "spend 47.00 more for free delivery" without a
separate endpoint, using exactly the same arithmetic the order will use.

Tested by `the_cart_reports_free_shipping_above_the_threshold`.

### 8. Carts are private — with **no admin override**

```php
final class CartItemPolicy
{
    // Note the deliberate absence of a before() admin bypass.
    private function owns(User $user, CartItem $item): bool
    {
        return $item->cart->user_id === $user->id;
    }
}
```

Tested by both `a_customer_cannot_touch_another_customers_cart_line` and
`an_admin_cannot_edit_someone_elses_cart_either`.

### 9. `PATCH` sets an absolute quantity, not a delta

```php
// An absolute quantity, not a delta. Removing a line is DELETE.
'quantity' => ['required', 'integer', 'min:1', 'max:'.config('marketplace.cart.max_quantity_per_item')],
```

Deltas are ambiguous under retries: a retried "+1" double-counts. An absolute
value is idempotent. Setting a quantity to zero is not "update to 0" — it is
`DELETE`, which is what REST already means.

## Eager loading

```php
private function withRelations(Cart $cart): Cart
{
    return $cart->load(['items.product.category']);
}
```

Every return path goes through this, so `CartResource` never triggers a lazy
load. Combined with `Model::shouldBeStrict()`, forgetting it fails the tests.

## Validation

| Field | Rules |
| --- | --- |
| `product_id` | required, integer, exists in `products` **where `is_active = true`** |
| `quantity` | required, integer, min 1, max `CART_MAX_QUANTITY_PER_ITEM` (100) |

The `exists` rule carries the `is_active` condition, so an inactive product
produces a clean 422 rather than reaching the service.

Tested by `an_inactive_product_cannot_be_added`.

## Error responses

| Situation | Status |
| --- | --- |
| Invalid / inactive product, bad quantity | 422 |
| Not enough stock | 409 with `errors.requested` and `errors.available` |
| Someone else's cart line | 403 |
| Guest | 401 |

## Tests

```
✓ a cart is created on first access and starts empty
✓ repeated access never creates a second cart
✓ a customer can add an item
✓ adding the same product twice increases the quantity
✓ it refuses to add more than the available stock
✓ accumulated quantity is checked against stock
✓ an inactive product cannot be added
✓ adding an item is validated
✓ a customer can change a line quantity
✓ a customer can remove a line
✓ a customer cannot touch another customers cart line
✓ an admin cannot edit someone elses cart either
✓ a guest has no cart
✓ the cart reports free shipping above the threshold
```
