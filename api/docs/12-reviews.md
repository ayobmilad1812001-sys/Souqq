# 12 — Product Reviews

Ratings and comments, gated by a verified-purchase rule.

## Files

| File | Role |
| --- | --- |
| `app/Models/Review.php` | Model |
| `app/Http/Controllers/Api/V1/ReviewController.php` | HTTP layer |
| `app/Http/Requests/Review/StoreReviewRequest.php` | Validation |
| `app/Http/Resources/ReviewResource.php` | Output shape |
| `app/Policies/ReviewPolicy.php` | **Verified-purchaser rule** |
| `app/Observers/ReviewObserver.php` | Rating cache invalidation |
| `tests/Feature/ProductReviewTest.php` | 9 tests |

## Endpoints

| Method | Endpoint | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/products/{id}/reviews` | public |
| `POST` | `/api/v1/products/{id}/reviews` | verified purchaser |
| `DELETE` | `/api/v1/reviews/{id}` | author or admin |

```json
{
  "success": true,
  "data": [
    {
      "id": 3,
      "rating": 5,
      "comment": "Arrived quickly and works well.",
      "author": { "id": 12, "name": "Amina Saleh" },
      "created_at": "2026-09-02T14:31:00+00:00"
    }
  ],
  "message": "Reviews retrieved."
}
```

---

## Design decisions

### 1. Only verified purchasers may review

```php
public function create(User $user, Product $product): bool
{
    if ($product->seller_id === $user->id) {
        return false;
    }

    return $user->orders()
        ->whereHas('items', fn ($query) => $query->where('product_id', $product->id))
        ->exists();
}
```

This is the single most effective guard against review spam. Anyone can create an
account; not everyone can create an *order*. Requiring a real purchase raises the
cost of a fake review from zero to the price of the product.

Note it is an `exists()` query, not a loaded collection — one indexed lookup
regardless of how many orders the customer has.

Tested by `a_customer_who_never_bought_the_product_cannot_review_it`.

### 2. A seller cannot review their own product

The first branch of the same method. Without it, a seller could buy their own
product (a real order, passing the check above) and post a five-star review.
Checking ownership first closes that loop.

Tested by `a_seller_cannot_review_their_own_product`.

### 3. One review per customer per product, enforced by the database

```php
$table->unique(['user_id', 'product_id']);
```

```php
$review = Review::query()->updateOrCreate(
    ['user_id' => $request->user()->id, 'product_id' => $product->id],
    $request->validated(),
);
```

A second submission **edits** the existing review rather than adding a duplicate.
Without the unique index a customer could post fifty reviews and dominate a
product's rating.

`updateOrCreate` is how the application cooperates with the index; the index is
the actual guarantee.

Tested by `submitting_a_second_review_replaces_the_first`, which asserts
`Review::count() === 1` after two submissions.

### 4. Deletion is author-only (with an admin override)

```php
public function before(User $user): ?bool
{
    return $user->isAdmin() ? true : null;
}

public function delete(User $user, Review $review): bool
{
    return $review->user_id === $user->id;
}
```

Unlike carts, reviews **do** get an admin bypass — moderating abusive content is
a legitimate platform responsibility. Sellers deliberately have no such power;
letting a seller delete a one-star review would make the whole rating system
worthless.

Tested by `a_customer_can_delete_their_own_review_but_not_anothers`.

---

## Average rating

Computed by the database on the product detail endpoint:

```php
Product::query()
    ->with(['category', 'seller:id,name'])
    ->withCount('reviews')
    ->withAvg('reviews', 'rating')
    ->find($productId);
```

One query with correlated subqueries — not N queries, and not a loaded collection
averaged in PHP.

The Resource reads the result defensively:

```php
// Read through getAttributes() rather than the magic property: strict mode
// throws on accessing an attribute that was never selected, and withAvg() is
// only applied on the detail endpoint.
'average_rating' => $this->when(
    ($average = $this->resource->getAttributes()['reviews_avg_rating'] ?? null) !== null,
    fn (): string => number_format((float) $average, 1)
),
```

`Model::shouldBeStrict()` makes reading an unselected attribute throw. Since the
same `ProductResource` serves both the listing (no average) and the detail page
(with average), the field has to be probed rather than accessed. Checking
`getAttributes()` avoids the magic accessor entirely.

Formatted to one decimal place: `"4.5"`.

Tested by `reviews_feed_the_average_rating_on_the_product_page`.

---

## Cache invalidation

```php
final readonly class ReviewObserver
{
    public function saved(Review $review): void
    {
        $this->cache->forgetProduct($review->product_id);
    }

    public function deleted(Review $review): void
    {
        $this->cache->forgetProduct($review->product_id);
    }
}
```

A new review changes the product's cached average rating and review count, so the
cached product entry must go. Same observer pattern as products and categories —
see [Caching & Invalidation](09-caching.md).

---

## Validation

| Field | Rules |
| --- | --- |
| `rating` | required, integer, 1–5 |
| `comment` | optional, nullable, string, max 2000 |

A rating with no comment is valid — many customers rate but do not write.

Tested by `review_input_is_validated` (rating of 9 → 422).

---

## Indexes

```php
$table->unique(['user_id', 'product_id']);   // one review per person per product
$table->index(['product_id', 'rating']);     // average-rating aggregation
```

The second serves `withAvg('reviews', 'rating')` as a covering index — the
aggregate can be answered from the index without touching the table rows.

---

## Tests

```
✓ anyone can read the reviews of a product
✓ a verified purchaser can review a product
✓ a customer who never bought the product cannot review it
✓ a seller cannot review their own product
✓ review input is validated
✓ submitting a second review replaces the first
✓ reviews feed the average rating on the product page
✓ a customer can delete their own review but not anothers
✓ a guest cannot post a review
```
