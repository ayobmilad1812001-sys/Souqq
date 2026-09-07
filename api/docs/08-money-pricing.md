# 08 — Money & Financial Precision

**No float ever touches a monetary value in this codebase.**

## Files

| File | Role |
| --- | --- |
| `app/Support/Money.php` | Immutable value object, integer minor units |
| `app/Services/PricingService.php` | All pricing arithmetic |
| `config/marketplace.php` | Shipping rules as strings |
| `tests/Unit/MoneyTest.php` | 13 tests (8 methods, one with 5 data sets) |
| `tests/Unit/PricingServiceTest.php` | 7 tests |

---

## Why this matters

Binary floating point cannot represent most decimal fractions exactly:

```php
0.1 + 0.2 === 0.3;   // false
```

In a marketplace this is not academic. Sum a few hundred line items with floats
and the total drifts by fractions of a cent. Those fractions become reconciliation
failures, invoices that do not match payments, and an accountant asking why the
books are off by 0.03.

**Test proving it:** `summing_many_small_amounts_stays_exact` adds `0.10` one
thousand times and asserts exactly `100.00`. The float equivalent does not.

---

## The four-layer defence

### Layer 1 — the database column

```php
$table->decimal('price', 10, 2);          // never float / double
$table->decimal('subtotal', 10, 2);
$table->decimal('shipping_cost', 10, 2);
$table->decimal('total', 10, 2);
$table->decimal('unit_price', 10, 2);
```

`DECIMAL(10,2)` stores the value exactly. `10,2` covers up to `99,999,999.99`.

### Layer 2 — the Eloquent cast

```php
protected function casts(): array
{
    return ['price' => 'decimal:2', ...];
}
```

`decimal:2` surfaces the value as a **string** (`"249.99"`). Casting to `float`
here would undo the entire point of the column — the value would be exact in the
database and lossy the instant PHP touched it.

### Layer 3 — the `Money` value object

```php
final readonly class Money implements JsonSerializable, Stringable
{
    private const MINOR_UNITS = 100;

    private function __construct(public int $minorUnits) {}
}
```

Stores an **integer number of minor units** (cents). All arithmetic is integer
arithmetic: exact, associative, and safe to fold over thousands of line items.

### Layer 4 — validation

```php
'price' => ['required', 'numeric', 'min:0.01', 'max:99999999.99', 'decimal:0,2'],
```

`decimal:0,2` matches the column exactly, so `10.999` is **rejected with a 422**
rather than silently rounded to `11.00` on insert. The client learns their input
was wrong instead of discovering a mystery penny later.

---

## The `Money` API

```php
Money::of('1234.50')            // parse a decimal string
Money::of(42)                   // integer major units
Money::zero()
Money::fromMinorUnits(12345)
Money::fromFloat(19.99)         // last resort, rounds immediately

$a->plus($b)
$a->minus($b)
$a->times(3)                    // whole quantities only
Money::sum([$a, $b, $c])

$a->equals($b)
$a->lessThan($b)
$a->greaterThanOrEqual($b)
$a->isZero()
$a->isNegative()

$a->toDecimalString()           // "1234.50" — DB / JSON boundary
```

### Parsing with half-up rounding

```php
if (! preg_match('/^(?<sign>[+-]?)(?<whole>\d+)(?:\.(?<fraction>\d+))?$/', $normalised, $matches)) {
    throw new InvalidArgumentException("Malformed monetary amount: [{$amount}].");
}

$minor = (int) $matches['whole'] * self::MINOR_UNITS;

if ($fraction !== '') {
    $twoDigits = (int) str_pad(substr($fraction, 0, 2), 2, '0');
    $remainder = substr($fraction, 2, 1);
    $minor += $twoDigits + ($remainder !== '' && (int) $remainder >= 5 ? 1 : 0);
}
```

- `"0.005"` → `0.01` (rounds half up)
- `"0.004"` → `0.00`
- `"99.9"` → `99.90`
- `"12.34.56"` → `InvalidArgumentException`

Malformed input **throws** rather than coercing to zero. A silent zero in a money
path is a bug that ships.

### Multiplication is by whole quantities only

```php
public function times(int $multiplier): self
{
    return new self($this->minorUnits * $multiplier);
}
```

The parameter is `int`, not `float`. You cannot buy 2.5 phones, and refusing the
type at the signature means no rounding decision ever has to be made here.

### Summation is order-independent

```php
public static function sum(iterable $amounts): self
{
    $total = 0;
    foreach ($amounts as $amount) {
        $total += $amount->minorUnits;
    }
    return new self($total);
}
```

Folding integers gives the same answer regardless of ordering. A float sum does
not — reordering the same line items can change the total.

### Immutability

`final readonly class` with a private constructor. Every operation returns a new
instance. A `Money` passed into a function cannot be mutated behind the caller's
back, which removes an entire category of aliasing bug from the money path.

---

## `PricingService`

Pure arithmetic. No database, no HTTP, no framework state — which is exactly why
it is trivially unit testable and why every total in the system is computed here
rather than inline in a controller.

```php
public function breakdown(iterable $lines): array
{
    $subtotal = $this->subtotal($lines);
    $shipping = $this->shippingCost($subtotal);

    return [
        'subtotal' => $subtotal,
        'shipping' => $shipping,
        'total'    => $this->total($subtotal, $shipping),
    ];
}
```

`breakdown()` exists so a caller cannot accidentally pair a subtotal with a
mismatched total — the three values are produced together or not at all.

### Shipping rules

```php
public function shippingCost(Money $subtotal): Money
{
    if ($subtotal->isZero()) {
        return Money::zero();                  // empty basket is never charged
    }

    $threshold = Money::of((string) config('marketplace.shipping.free_threshold'));

    if ($subtotal->greaterThanOrEqual($threshold)) {
        return Money::zero();                  // free above the threshold
    }

    return Money::of((string) config('marketplace.shipping.flat_rate'));
}
```

Three cases, each tested:

| Subtotal | Shipping |
| --- | --- |
| `0.00` | `0.00` |
| `499.99` | `15.00` |
| `500.00` | `0.00` (inclusive at the threshold) |

---

## Configuration

```php
'shipping' => [
    'flat_rate'      => (string) env('SHIPPING_FLAT_RATE', '15.00'),
    'free_threshold' => (string) env('SHIPPING_FREE_THRESHOLD', '500.00'),
],
```

Note the explicit `(string)` casts. `env()` returns whatever the `.env` file
looks like; forcing a string keeps the value out of float territory even in
config, and `Money::of()` parses it exactly.

Operations staff tune these per market without a deploy.

---

## Money at the API boundary

Prices are emitted as **JSON strings**, not numbers:

```json
{ "price": "249.99", "subtotal": "200.00", "shipping_cost": "15.00", "total": "215.00" }
```

```php
'price' => (string) $this->price,
```

A JSON *number* would be parsed by a JavaScript client into a float, reintroducing
the exact problem at the last possible moment. A string forces the client to make
a deliberate decision about how to handle it.

---

## Aggregates

Even SQL aggregates are re-parsed rather than trusted as floats:

```php
$revenue = Order::query()->where('status', '!=', OrderStatus::Cancelled)->sum('total');

return ['gross_revenue' => Money::of((string) ($revenue ?: '0.00'))->toDecimalString()];
```

---

## Tests

**`MoneyTest`**

```
✓ it parses decimal strings exactly
✓ it rounds extra decimal places half up
✓ it handles negative amounts
✓ it rejects malformed input
✓ summing many small amounts stays exact
✓ multiplication by quantity is exact
✓ subtraction and comparison behave
✓ sum folds a collection
✓ it round trips through its string form (5 data sets)
```

**`PricingServiceTest`**

```
✓ it multiplies a line exactly
✓ it sums lines without drift
✓ shipping is flat below the free threshold
✓ shipping is free at and above the threshold
✓ an empty basket is never charged shipping
✓ breakdown returns a consistent triple
✓ a large free shipping basket totals to its subtotal
```

`it_sums_lines_without_drift` is the one to read: `0.30 + 0.20 + 39.98` lands on
`40.479999...` in float arithmetic and on exactly `40.48` here.
