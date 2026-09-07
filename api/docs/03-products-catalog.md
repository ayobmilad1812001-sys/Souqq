# 03 — Product Catalog

CRUD plus the read path that carries most of the traffic: search, filtering, sorting and pagination.

## Files

| File | Role |
| --- | --- |
| `app/Models/Product.php` | Model + all query scopes |
| `app/Services/ProductService.php` | Business logic, cache orchestration |
| `app/Http/Controllers/Api/V1/ProductController.php` | Thin HTTP layer |
| `app/Http/Requests/Product/IndexProductRequest.php` | Filter validation |
| `app/Http/Requests/Product/StoreProductRequest.php` | Create rules |
| `app/Http/Requests/Product/UpdateProductRequest.php` | Update rules |
| `app/Http/Resources/ProductResource.php` | Output shape |
| `app/Policies/ProductPolicy.php` | Seller isolation |
| `app/Observers/ProductObserver.php` | Cache invalidation |
| `tests/Feature/ProductCatalogTest.php` | 15 read-path tests |
| `tests/Feature/ProductManagementTest.php` | 14 write-path tests |

## Endpoints

| Method | Endpoint | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/products` | public |
| `GET` | `/api/v1/products/{id}` | public |
| `POST` | `/api/v1/products` | seller, admin |
| `PATCH` | `/api/v1/products/{id}` | owner or admin |
| `DELETE` | `/api/v1/products/{id}` | owner or admin |

## Filtering

| Parameter | Example | Behaviour |
| --- | --- | --- |
| `search` | `search=wireless` | Matches name **or** description |
| `category` | `category=3` or `category=books` | Accepts an id **or** a slug |
| `min_price` / `max_price` | `min_price=20&max_price=100` | `max_price` must be ≥ `min_price` |
| `in_stock` | `in_stock=1` | Only `stock_quantity > 0` |
| `mine` | `mine=1` | Seller's own inventory, including inactive |
| `sort` | `price_asc` | Whitelisted (see below) |
| `per_page` / `page` | `per_page=20&page=2` | `per_page` capped at 100 |

```http
GET /api/v1/products?search=keyboard&category=electronics&min_price=50&max_price=300&sort=price_asc&per_page=20
```

## Design decisions

### 1. Filters live in query scopes, not in the controller

```php
Product::query()
    ->search($filters['search'] ?? null)
    ->inCategory($filters['category'] ?? null)
    ->priceBetween($filters['min_price'] ?? null, $filters['max_price'] ?? null)
    ->sorted($filters['sort'] ?? null);
```

Each scope is a no-op when its filter is absent (`if (blank($term)) return;`), so
the caller never writes conditional query building. The controller stays a
translation layer over validated input.

### 2. `sort` is whitelisted in **two** places

In the Form Request:

```php
'sort' => ['sometimes', 'nullable', Rule::in([
    'price_asc', 'price_desc', 'name_asc', 'name_desc', 'newest', 'oldest',
])],
```

and again in the scope:

```php
match ($sort) {
    'price_asc'  => $query->orderBy('price'),
    'price_desc' => $query->orderByDesc('price'),
    ...
    default      => $query->orderByDesc('created_at'),
};
```

The request gives the client a helpful 422. The `match` guarantees that **no
user-controlled string can ever reach an `ORDER BY` clause**, even if the request
layer is bypassed by an internal caller.

Tested by `it_rejects_an_unknown_sort_key`.

### 3. Sorting always has a deterministic tiebreaker

```php
$query->orderBy('id');
```

Without this, rows sharing a price can appear on two consecutive pages — or on
neither. This is one of the most common and least-noticed pagination bugs.

### 4. LIKE wildcards in the search term are escaped

```php
private const LIKE_ESCAPE = '!';

$escaped = str_replace(
    [self::LIKE_ESCAPE, '%', '_'],
    [self::LIKE_ESCAPE.self::LIKE_ESCAPE, self::LIKE_ESCAPE.'%', self::LIKE_ESCAPE.'_'],
    $term
);

$inner->whereRaw("name LIKE ? ESCAPE '".self::LIKE_ESCAPE."'", [$pattern]);
```

A shopper searching for `50%` must match the literal text, not every row in the
table. Two details worth noting:

- **An explicit `ESCAPE` clause is mandatory.** Escaping the term without it
  does nothing at all — the escape character is not special by default.
- **The escape character is `!`, not a backslash.** A backslash literal is
  interpreted differently by MySQL (which processes backslash escapes in string
  literals) and SQLite (which does not), so a backslash-based escape works in one
  and breaks in the other. `!` is unambiguous in both.

Tested by `search_escapes_like_wildcards`.

### 5. Category filter accepts an id **or** a slug

```php
if (is_numeric($category)) {
    $query->where('category_id', (int) $category);
    return;
}

$query->whereHas('category', fn (Builder $inner) => $inner->where('slug', $category));
```

Clients use whichever identifier they already hold — a mobile app has the id, a
web front end has the slug from the URL.

### 6. Page size is capped

```php
'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
```

An uncapped `per_page` is a denial-of-service vector: `?per_page=1000000`
serialises the entire catalogue into memory.

### 7. Deleting a **sold** product deactivates it instead

```php
public function delete(Product $product): void
{
    if ($product->orderItems()->exists()) {
        $product->update(['is_active' => false]);
        return;
    }

    $product->delete();
}
```

A product cited by an order is financial history. The foreign key from
`order_items` is `RESTRICT`, so a hard delete would fail anyway — this turns a
database error into correct, intentional behaviour.

Tested by `deleting_a_sold_product_deactivates_it_instead`.

### 8. Price precision is validated, not rounded

```php
'price' => ['required', 'numeric', 'min:0.01', 'max:99999999.99', 'decimal:0,2'],
```

`decimal:0,2` matches the `DECIMAL(10,2)` column exactly, so `10.999` is rejected
with a 422 rather than silently becoming `11.00` on insert. See
[Money & Pricing](08-money-pricing.md).

Tested by `a_price_with_more_than_two_decimals_is_rejected`.

## N+1 prevention

```php
->with(['category', 'seller:id,name'])
```

and in the Resource:

```php
'category' => CategoryResource::make($this->whenLoaded('category')),
```

`whenLoaded` omits a relation that was not eager loaded rather than lazily
fetching it per row. Combined with `Model::shouldBeStrict()` in non-production
environments, a missing eager load **fails the test suite** instead of quietly
issuing 200 queries.

## Indexing

```php
$table->index(['category_id', 'is_active', 'price']);
$table->index(['seller_id', 'is_active']);
```

The composite is ordered to serve the common listing query — filter by category,
hide inactive, sort by price — from a single index. Column order in a composite
index matters: equality predicates first, range/sort last.

**Known limitation:** `LIKE '%term%'` cannot use a B-tree index. This is correct
and acceptable at this scale; swap in a MySQL `FULLTEXT` index or a dedicated
search engine when the catalogue outgrows it. The comment saying so is in the
scope itself.

## Caching

Listings and single-product reads are cached in Redis and invalidated by
`ProductObserver` on every write. Personalised views (`?mine=1`, admin) bypass
the shared cache entirely. Full detail in [Caching & Invalidation](09-caching.md).

## Tests

**Read path** — `ProductCatalogTest`

```
✓ guests can browse the catalogue
✓ inactive products are hidden from shoppers
✓ it searches name and description
✓ search escapes like wildcards
✓ it filters by category id and by slug
✓ it filters by price range
✓ it rejects a max price below the min price
✓ it sorts by price in both directions
✓ it rejects an unknown sort key
✓ it paginates with a capped page size
✓ it can filter to products that are in stock
✓ a single product can be retrieved with its review count
✓ a missing product returns the error envelope
✓ an inactive product is hidden from shoppers but visible to its seller
✓ a seller can list only their own inventory
```

**Write path** — `ProductManagementTest`

```
✓ a seller can create a product
✓ a seller cannot assign a product to another seller
✓ a customer cannot create a product
✓ a guest cannot create a product
✓ product creation is validated
✓ a price with more than two decimals is rejected
✓ a duplicate sku is rejected
✓ a seller can update their own product
✓ a seller cannot update another sellers product
✓ a seller cannot delete another sellers product
✓ a seller can delete their own unsold product
✓ deleting a sold product deactivates it instead
✓ an admin can manage any sellers product
✓ a seller sees only their own sales statistics
```
