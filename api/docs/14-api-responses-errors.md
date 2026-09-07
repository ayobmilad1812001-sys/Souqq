# 14 — API Responses & Error Handling

One envelope, one place that builds it, and **zero `try/catch` blocks in controllers**.

## Files

| File | Role |
| --- | --- |
| `app/Support/ApiResponse.php` | The envelope |
| `bootstrap/app.php` | Central exception rendering |
| `app/Exceptions/DomainException.php` | Base for business-rule failures |
| `app/Http/Middleware/ForceJsonResponse.php` | Guarantees JSON, always |

---

## The envelope

### Success

```json
{
  "success": true,
  "data": { },
  "message": "Operation successful."
}
```

### Paginated

```json
{
  "success": true,
  "data": [ ],
  "message": "Products retrieved.",
  "meta":  { "current_page": 2, "total": 25, "per_page": 10, "last_page": 3 },
  "links": { "first": "...", "last": "...", "prev": "...", "next": "..." }
}
```

`meta` and `links` sit **alongside** `data`, not nested inside it, so a client
reads pagination from a stable location regardless of endpoint.

### Validation error — 422

```json
{
  "success": false,
  "message": "Validation failed.",
  "errors": { "price": ["The price field must have 0-2 decimal places."] }
}
```

### Domain error — 409

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

---

## `ApiResponse`

```php
ApiResponse::success($data, 'Message.');           // 200
ApiResponse::created($data, 'Resource created.');  // 201
ApiResponse::deleted('Resource deleted.');         // 200
ApiResponse::error('Message.', 409, $context);     // any
ApiResponse::validationError($errors);             // 422
```

Keeping this in one class makes a change to the contract a **one-file change**
rather than a search-and-replace across every controller.

### Why `deleted()` returns 200, not 204

```php
public static function deleted(string $message = 'Resource deleted.'): JsonResponse
{
    // 200 rather than 204: the envelope requires a body, and a 204 response
    // carrying a body is invalid HTTP.
    return self::success(null, $message);
}
```

The contract promises `success` and `message` on every response. A `204 No
Content` with a body violates the HTTP spec, and many clients discard it. Rather
than carve out an inconsistent exception for deletes, deletes return 200.

### Normalisation

```php
private static function normalise(mixed $data): mixed
{
    if ($data instanceof ResourceCollection || $data instanceof JsonResource) {
        $resolved = $data->response()->getData(assoc: true);

        return $resolved['data'] ?? $resolved;
    }

    if ($data instanceof AbstractPaginator) return $data->items();
    if ($data instanceof Arrayable)         return $data->toArray();

    return $data;
}
```

Laravel Resources wrap themselves in their own `data` key. Passing one straight
through would produce `data.data`. Unwrapping here means a controller can hand
over a Resource, a Collection, a paginator, an array or `null` and the envelope
comes out identical.

---

## Central exception rendering

Every exception leaving the application is converted in `bootstrap/app.php`:

```php
$exceptions->shouldRenderJsonWhen(fn () => true);

$exceptions->render(fn (ValidationException $e)     => ApiResponse::validationError($e->errors()));
$exceptions->render(fn (AuthenticationException $e) => ApiResponse::error('Unauthenticated.', 401));
$exceptions->render(fn (AuthorizationException $e)  => ApiResponse::error($e->getMessage() ?: 'This action is unauthorized.', 403));
$exceptions->render(fn (ModelNotFoundException $e)  => ApiResponse::error('Resource not found.', 404));
$exceptions->render(fn (NotFoundHttpException $e)   => ApiResponse::error('Resource not found.', 404));
$exceptions->render(fn (TooManyRequestsHttpException $e) => ApiResponse::error('Too many requests. Please slow down.', 429));

$exceptions->render(fn (DomainException $e) => ApiResponse::error($e->getMessage(), $e->status(), $e->context()));

$exceptions->render(function (Throwable $e) {
    if (config('app.debug')) {
        return null;   // fall through to Laravel's verbose debug handler
    }

    return ApiResponse::error('Server error.', 500);
});
```

Two details:

- **`ModelNotFoundException` becomes a generic "Resource not found."** Laravel's
  default message leaks the model class name and the id that was searched for —
  useful in a stack trace, unnecessary in a public API response.
- **The catch-all returns `null` when `app.debug` is on.** Returning `null` tells
  Laravel "I did not handle this", so developers still get the full stack trace
  locally while production gets a bare `Server error.`

---

## Domain exceptions

```php
abstract class DomainException extends RuntimeException
{
    protected array $context = [];

    public function status(): int { return 422; }

    public function context(): array { return $this->context; }
}
```

| Exception | Status | Meaning |
| --- | --- | --- |
| `EmptyCartException` | 422 | Checkout with nothing in the cart |
| `ProductUnavailableException` | 422 | A carted product was deactivated |
| `InsufficientStockException` | **409** | Not enough stock, with the true figure |
| `InvalidStatusTransitionException` | **409** | Illegal move in the state machine |
| `OrderNotCancellableException` | **409** | Wrong status, or the window closed |

### Why these are exceptions and not return values

They are **expected outcomes**, not bugs — a shopper racing another shopper for
the last unit is normal marketplace behaviour. But they must abort the
surrounding `DB::transaction`, and throwing is how a transaction rolls back.

Returning an error object would require every caller in the transaction to check
and manually roll back, and the first caller to forget would leave a half-written
order in the database.

### 422 vs 409

- **422** — the request itself is wrong (empty cart, deactivated product). Retrying
  identically will fail identically.
- **409** — the request is well formed but conflicts with current state. A retry,
  or a retry with a smaller quantity, may succeed.

That distinction lets a client decide whether to show "fix your input" or
"try again".

### Machine-readable context

```php
final class InsufficientStockException extends DomainException
{
    public function __construct(Product $product, int $requested, int $available)
    {
        $this->context = [
            'product'   => ['id' => $product->id, 'name' => $product->name, 'sku' => $product->sku],
            'requested' => $requested,
            'available' => $available,
        ];

        parent::__construct(sprintf(...));
    }
}
```

A client can render "only 1 left — buy it?" instead of parsing an English
sentence. And because the exception is raised **while holding the row lock**, the
`available` figure is authoritative.

---

## `ForceJsonResponse`

```php
final class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
```

Without this, a client that forgets `Accept: application/json` gets an HTML error
page — or a redirect to a `login` route that does not exist in an API-only
application, producing a confusing `RouteNotFoundException` instead of a clean
401.

Rewriting the header at the edge means **every** failure mode is machine-readable,
including the ones the application does not anticipate.

---

## Status codes

| Code | When |
| --- | --- |
| 200 | OK, including deletes |
| 201 | Resource created |
| 401 | Missing or invalid token |
| 403 | Authenticated but not permitted |
| 404 | No such resource |
| 409 | Conflict: stock, illegal transition, non-empty category |
| 422 | Validation failure or business-rule violation |
| 429 | Rate limited |
| 500 | Unhandled — `Server error.` in production |

---

## The result: controllers stay tiny

```php
public function store(StoreProductRequest $request): JsonResponse
{
    $this->authorize('create', Product::class);

    return ApiResponse::created(
        ProductResource::make($this->products->create($request->user(), $request->validated())),
        'Product created.'
    );
}
```

No `try`. No `catch`. No status-code arithmetic. Validation happened in the Form
Request, authorization in the Policy, business logic in the Service, shaping in
the Resource, and every possible failure is rendered centrally.
