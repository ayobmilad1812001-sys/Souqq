# 02 — Authorization & RBAC

Three roles, enforced in two layers: coarse **middleware** gates on route groups, and per-record **policies** for ownership.

## Files

| File | Role |
| --- | --- |
| `app/Enums/UserRole.php` | `customer`, `seller`, `admin` |
| `app/Http/Middleware/EnsureUserHasRole.php` | Coarse route-group gate |
| `app/Policies/ProductPolicy.php` | **Seller isolation** |
| `app/Policies/OrderPolicy.php` | Three different views of one order |
| `app/Policies/CartItemPolicy.php` | Cart privacy (no admin bypass) |
| `app/Policies/CategoryPolicy.php` | Admin-only taxonomy |
| `app/Policies/ReviewPolicy.php` | Verified-purchaser rule |

## Permission matrix

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

## Two layers, two jobs

### Layer 1 — middleware answers "is this the right *kind* of actor?"

```php
Route::middleware('role:seller,admin')->group(function (): void {
    Route::post('products', [ProductController::class, 'store']);
    Route::patch('products/{product}', [ProductController::class, 'update']);
    Route::delete('products/{product}', [ProductController::class, 'destroy']);
    Route::get('stats', StatsController::class);
});
```

This is a cheap 403 before any database work happens. It keeps policies from
having to re-check the role on every single method.

### Layer 2 — policies answer "may *you* do this to *this record*?"

```php
$this->authorize('update', $product);
```

Middleware alone would let seller A edit seller B's product. The policy is what
stops that.

## Multi-tenant seller isolation

**The rule:** a seller may only ever touch rows where `products.seller_id === $user->id`.

It is expressed **once**, in `ProductPolicy`:

```php
private function owns(User $user, Product $product): bool
{
    return $user->role->canSell() && $product->seller_id === $user->id;
}
```

Every write endpoint routes through it. There is no second place to forget it.

### Ownership is taken from the token, never the payload

```php
'seller_id' => $attributes['seller_id'] ?? $seller->id,
```

and on update:

```php
// seller_id is never mass-assignable from a request.
unset($attributes['seller_id']);
```

A seller POSTing `{"seller_id": 99}` gets a product owned by **themselves**, not
by user 99. Tested by `a_seller_cannot_assign_a_product_to_another_seller`.

### Order listings are scoped in the query, not filtered afterwards

```php
private function scopedQuery(User $user): Builder
{
    if ($user->isAdmin())  return Order::query();
    if ($user->isSeller()) return Order::query()->containingProductsOf($user->id);

    return Order::query()->where('user_id', $user->id);
}
```

Filtering a result set in PHP *after* pagination is a classic leak: page 2 of a
"filtered" list can contain rows that should never have been fetched. Scoping the
query means the rows never leave the database.

## The `before()` hook

```php
public function before(User $user): ?bool
{
    return $user->isAdmin() ? true : null;
}
```

Returning `true` short-circuits every check; returning `null` falls through to
the individual method. This keeps admin handling in one place and lets each
policy method focus purely on the seller/customer rule.

## Two deliberate asymmetries

### 1. `CartItemPolicy` has **no** admin bypass

```php
final class CartItemPolicy
{
    // Note the absence of before().
    private function owns(User $user, CartItem $item): bool
    {
        return $item->cart->user_id === $user->id;
    }
}
```

Nobody, including staff, edits someone else's basket. An admin who needs to
intervene does so through a support flow that leaves an audit trail, not by
silently mutating a live cart.

Tested by `an_admin_cannot_edit_someone_elses_cart_either`.

### 2. A seller may **advance** an order but never **cancel** it

```php
public function updateStatus(User $user, Order $order, OrderStatus $target): bool
{
    if (! $user->isSeller())                  return false;
    if (! $order->belongsToSeller($user->id)) return false;

    return $target !== OrderStatus::Cancelled;
}
```

An order can contain goods from several sellers. Letting seller A cancel it
would destroy seller B's sale. Cancellation is a platform decision (admin) or a
customer decision (within the window).

Tested by `a_seller_cannot_cancel_an_order`.

## Policy arguments with extra context

`updateStatus` needs to know the *target* status, not just the order:

```php
$this->authorize('updateStatus', [$order, $target]);
```

Laravel passes the array as additional arguments after `$user`. This is how an
authorization rule can depend on what is being changed *to*, not only on what is
being changed.

## Guest-friendly policies

Public catalogue methods accept a nullable user:

```php
public function view(?User $user, Product $product): bool
{
    if ($product->is_active) {
        return true;
    }

    return $user !== null && $this->owns($user, $product);
}
```

An inactive product stays visible to its own seller (so they can edit it back
into the catalogue) but is hidden from shoppers and guests.

Tested by `an_inactive_product_is_hidden_from_shoppers_but_visible_to_its_seller`.

## Failure response

Every authorization failure produces the same envelope, rendered centrally in
`bootstrap/app.php`:

```json
{
  "success": false,
  "message": "This action is unauthorized."
}
```

HTTP 403. No controller contains a `try/catch` for this.
