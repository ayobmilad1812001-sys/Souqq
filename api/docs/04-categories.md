# 04 — Categories

The product taxonomy. Small, read on almost every catalogue request, changes rarely — the textbook case for a cached read.

## Files

| File | Role |
| --- | --- |
| `app/Models/Category.php` | Model |
| `app/Services/CategoryService.php` | Slug generation, delete protection |
| `app/Http/Controllers/Api/V1/CategoryController.php` | HTTP layer |
| `app/Http/Requests/Category/StoreCategoryRequest.php` | Create rules |
| `app/Http/Requests/Category/UpdateCategoryRequest.php` | Update rules |
| `app/Policies/CategoryPolicy.php` | Admin-only writes |
| `app/Observers/CategoryObserver.php` | Cache invalidation |
| `tests/Feature/CategoryManagementTest.php` | 10 tests |

## Endpoints

| Method | Endpoint | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/categories` | public |
| `GET` | `/api/v1/categories/{id}` | public |
| `POST` | `/api/v1/categories` | admin |
| `PATCH` | `/api/v1/categories/{id}` | admin |
| `DELETE` | `/api/v1/categories/{id}` | admin |

```json
{
  "success": true,
  "data": [
    { "id": 1, "name": "Electronics", "slug": "electronics", "products_count": 42 }
  ],
  "message": "Categories retrieved."
}
```

## Design decisions

### 1. The taxonomy is platform-owned

```php
public function create(User $user): bool
{
    return $user->isAdmin();
}
```

Sellers may read categories but never reshape them. A category rename affects
**every seller's** listings, so it is not a decision any single seller gets to
make. Letting sellers create categories also produces the classic marketplace
mess: "Electronics", "electronics", "Electronic", "Elecronics".

Tested by `a_seller_cannot_manage_the_taxonomy`.

### 2. Slugs are generated and made unique automatically

```php
private function uniqueSlug(string $name): string
{
    $base = Str::slug($name);
    $slug = $base;
    $suffix = 2;

    while (Category::query()->where('slug', $slug)->exists()) {
        $slug = "{$base}-{$suffix}";
        $suffix++;
    }

    return $slug;
}
```

Creating "Books" when `books` already exists yields `books-2` rather than a 422.
The database still carries a `unique` index on `slug` as the real guarantee —
this loop is convenience, not correctness.

Tested by `slugs_are_made_unique_automatically`.

### 3. Renaming does **not** change the slug

```php
if (isset($attributes['name']) && ! isset($attributes['slug'])) {
    // Renaming without an explicit slug keeps the old slug so existing
    // catalogue links and bookmarks do not break.
    unset($attributes['slug']);
}
```

Slugs are public identifiers that appear in URLs, bookmarks, shared links and
search-engine indexes. Silently regenerating one on a typo fix would break every
inbound link to that category. An admin who genuinely wants a new slug passes it
explicitly.

Tested by `an_admin_can_rename_a_category`, which asserts the name changed and
the slug did not.

### 4. A category holding products cannot be deleted

```php
public function delete(Category $category): bool
{
    if ($category->products()->exists()) {
        return false;
    }

    $category->delete();

    return true;
}
```

The controller turns `false` into a **409 Conflict**:

```json
{
  "success": false,
  "message": "This category still contains products and cannot be deleted."
}
```

Three options were available and two were rejected:

| Option | Verdict |
| --- | --- |
| `CASCADE` — delete the products too | Destroys other sellers' inventory. Unacceptable. |
| Reassign to "Uncategorised" | A surprising side effect that silently rewrites sellers' data. |
| **Refuse with 409** | The admin decides what happens to the products first. ✅ |

The foreign key is `restrictOnDelete()` at the database level too, so even a
direct SQL delete is refused.

Tested by `a_category_with_products_cannot_be_deleted`.

### 5. `products_count` counts only **active** products

```php
Category::query()
    ->withCount(['products' => fn ($query) => $query->where('is_active', true)])
    ->orderBy('name')
    ->get();
```

A shopper clicking "Books (12)" and finding 3 products would be a bug. The count
reflects what the catalogue will actually show them.

`withCount` produces one aggregate query for the whole list, not one per
category. The Resource exposes it via `whenCounted('products')`, which safely
omits the field if the count was not requested.

## Caching

The whole list lives under one key:

```php
public const CATEGORY_LIST = 'categories:all';
```

`CategoryObserver` forgets it on any save or delete, **and** bumps the product
listing version — because a category name is embedded in every product payload
and the `?category=` filter resolves through it.

```php
public function forgetCategories(): void
{
    Cache::forget(CacheKeys::CATEGORY_LIST);
    $this->bumpListingVersion();
}
```

Tested by `creating_a_category_purges_the_cached_list`.

## Tests

```
✓ anyone can list categories
✓ an admin can create a category
✓ slugs are made unique automatically
✓ a seller cannot manage the taxonomy
✓ a customer cannot manage the taxonomy
✓ a guest cannot manage the taxonomy
✓ category creation is validated
✓ an admin can rename a category
✓ an admin can delete an empty category
✓ a category with products cannot be deleted
```
