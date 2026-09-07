# 10 — Queues & Background Jobs

Nothing that can be deferred runs in the request path.

## Files

| File | Role |
| --- | --- |
| `app/Jobs/SendOrderConfirmationJob.php` | Confirmation email |
| `app/Jobs/RecordInventoryMovementJob.php` | Stock ledger write |
| `app/Mail/OrderConfirmationMail.php` | Mailable |
| `resources/views/emails/order-confirmation.blade.php` | Email template |
| `config/queue.php` | Redis connection, `after_commit` |
| `docker-compose.yml` | The `queue-worker` service |

---

## Configuration

```php
'redis' => [
    'driver'       => 'redis',
    'connection'   => env('REDIS_QUEUE_CONNECTION', 'default'),
    'queue'        => env('REDIS_QUEUE', 'default'),
    'retry_after'  => (int) env('REDIS_QUEUE_RETRY_AFTER', 90),
    'after_commit' => true,
],
```

Redis is both the cache and the queue broker, on **separate databases** (queue on
`0`, cache on `1`) so `cache:clear` cannot destroy queued jobs.

---

## The `after_commit` flag

The most important line in the queue config:

```php
'after_commit' => true,
```

`RecordInventoryMovementJob` is dispatched from **inside** the checkout
transaction. Without this flag:

```
T1: BEGIN
T1: UPDATE products SET stock_quantity = 9
T1: dispatch RecordInventoryMovementJob  →  pushed to Redis IMMEDIATELY
                                            Worker picks it up on another machine
                                            Queries for order #501 → NOT FOUND
T1: INSERT INTO orders ...
T1: COMMIT                                  ← too late
```

The job would run against data that is not yet visible to any other connection.
`after_commit` holds the push until the transaction commits, so a worker can never
observe a half-built world.

This is a race that only appears under load, on a multi-worker deployment, and is
miserable to diagnose. One config line prevents the whole class.

---

## Job 1 — `SendOrderConfirmationJob`

```php
final class SendOrderConfirmationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public readonly int $orderId) {}

    public function handle(): void
    {
        $order = Order::query()->with(['items.product', 'user'])->find($this->orderId);

        if ($order === null) {
            return;   // deleted between dispatch and execution; not a failure
        }

        Mail::to($order->user->email)->send(new OrderConfirmationMail($order));
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Order confirmation email permanently failed.', [
            'order_id'  => $this->orderId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
```

### Why it is queued

An SMTP round trip adds **hundreds of milliseconds** to `POST /orders` — the
slowest and most conversion-sensitive endpoint in the API, the one already
holding database row locks. Worse, if the mail relay is down, an inline send
would surface to the shopper as a **failed checkout for an order that actually
succeeded**. They would retry and buy twice.

Queuing decouples the two: the order succeeds, the email retries on its own.

### Design details

**It carries an `int`, not a model.**

```php
public function __construct(public readonly int $orderId) {}
```

A serialised model is a snapshot from dispatch time. Re-fetching inside `handle()`
means the email reflects the order as it is *now*, and the payload stays tiny.
(Laravel's `SerializesModels` would also re-fetch, but passing the id makes the
intent explicit and the failure mode — a deleted order — easy to handle.)

**A missing order returns quietly.** It is not a failure worth three retries and
an error log; the order was legitimately deleted.

**Backoff grows: 10s, 60s, 300s.** Transient SMTP failures usually clear in
seconds. Hammering a struggling relay every 10 seconds makes the outage worse.

**`failed()` logs with context.** After the third attempt the job is written to
the `failed_jobs` table and a log line records which order never got its email —
so support can resend rather than discovering it from a customer complaint.

---

## Job 2 — `RecordInventoryMovementJob`

```php
final class RecordInventoryMovementJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly int $productId,
        public readonly ?int $orderId,
        public readonly string $reason,
        public readonly int $quantityDelta,
        public readonly int $resultingQuantity,
    ) {}

    public function handle(): void
    {
        InventoryMovement::query()->create([...]);
    }
}
```

### Why it is queued

Ledger writes are **audit data**, not part of the correctness of the sale. Writing
them inline would extend the **row-lock hold time** inside checkout — and while
that lock is held, every other shopper wanting that SKU is blocked. Milliseconds
there are multiplied by every concurrent buyer.

`tries = 5` because losing an audit row is worse than retrying it, and the write
is idempotent enough in practice (a duplicate ledger row is visible and harmless;
a missing one is invisible and harmful).

---

## Failed jobs persist to MySQL

```php
'failed' => [
    'driver'   => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
    'database' => env('DB_CONNECTION', 'mysql'),
    'table'    => 'failed_jobs',
],
```

Redis is the runtime broker, but failures go to **MySQL**. A Redis flush — during
an incident, a memory-pressure eviction, a `FLUSHDB` typed into the wrong
terminal — can never lose the failure audit trail.

```bash
php artisan queue:failed          # list
php artisan queue:retry all       # retry
php artisan queue:flush           # discard
```

---

## The worker

```yaml
queue-worker:
  build:
    context: .
    dockerfile: docker/Dockerfile      # same image as `app`
  command: php artisan queue:work redis --queue=default --tries=3 --backoff=10 --max-time=3600 --sleep=1
```

**Same image as the web tier**, different command. This guarantees a worker can
never run a different revision of the code than the application that dispatched
the job — a genuinely nasty class of bug when the two drift.

| Flag | Reason |
| --- | --- |
| `--tries=3` | Ceiling; individual jobs override via `$tries` |
| `--backoff=10` | Default gap between attempts |
| `--max-time=3600` | Recycle the process hourly so a long-lived worker cannot leak memory indefinitely |
| `--sleep=1` | Poll interval when the queue is empty |

The Docker image installs the **`pcntl`** extension specifically so `queue:work`
can handle `SIGTERM` and finish the job in hand instead of being killed mid-write
during a deploy.

---

## Behaviour in tests

`phpunit.xml` sets `QUEUE_CONNECTION=sync`, so jobs execute inline and their
effects are directly assertable. Where the point is that something was
**deferred**, the test fakes the bus instead:

```php
#[Test]
public function the_confirmation_email_is_queued_rather_than_sent_inline(): void
{
    Bus::fake();

    // ... place an order ...

    Bus::assertDispatched(SendOrderConfirmationJob::class);
}
```

This asserts the *architecture*, not just the outcome — an inline `Mail::send()`
would still deliver the email and still pass a naive test, but would fail this one.

---

## Running the worker

```bash
# Docker — already running as a service
docker compose logs -f queue-worker

# Local
php artisan queue:work redis

# Local, restart on code change
php artisan queue:listen redis
```

In production, supervise the worker (Supervisor, systemd, or the container
restart policy already set to `unless-stopped`) and run more than one replica.
