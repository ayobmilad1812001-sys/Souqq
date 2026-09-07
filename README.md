# Souqa

A production-oriented e-commerce marketplace, built as two independent applications: a headless REST API and a single-page storefront that consumes it.

The name comes from **سوق** (*souq*, market).

![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white)
![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?logo=laravel&logoColor=white)
![React](https://img.shields.io/badge/React-19-61DAFB?logo=react&logoColor=black)
![TypeScript](https://img.shields.io/badge/TypeScript-6-3178C6?logo=typescript&logoColor=white)
![Tests](https://img.shields.io/badge/tests-141%20passing-3FB950)

---

## What this is

Most CRUD portfolio projects fall over the moment two people click "buy" at the
same instant. This one was built to survive that, and the test suite proves it.

The engineering weight sits in four places:

| Concern | How it is handled |
| --- | --- |
| **Concurrency** | `SELECT … FOR UPDATE` held inside the checkout transaction, with locks always taken in ascending id order so overlapping carts queue instead of deadlocking |
| **Atomicity** | The whole checkout is one `DB::transaction`; a failure injected midway is proven to roll back every write, including the stock decrement |
| **Money** | Never a float. `DECIMAL(10,2)` in MySQL → integer minor units in PHP → a JSON **string** on the wire → integer minor units again in the browser |
| **Cache invalidation** | Hung off Eloquent observers, not services, so no write path can leave a stale price or stock figure in Redis |

Each of those is documented with the reasoning, the alternatives rejected, and
the tests that prove it — see [`api/docs/`](api/docs/README.md).

---

## Repository layout

```
souqa/
├── api/          Laravel 12 REST API      →  api/README.md
│   └── docs/     19 feature documents     →  api/docs/README.md
└── web/          React 19 + Vite SPA      →  web/README.md
```

They are separate applications, not a framework and its templates. The API
serves JSON only — there is no `routes/web.php`, no Blade views, and a
`Content-Security-Policy: default-src 'none'` on the web tier. That is what
lets the same API also serve a mobile client or a partner integration later.

---

## Running it locally

You need **two terminals**. The storefront is an empty shell without the API.

**Terminal 1 — the API** (http://localhost:8000)

```bash
cd api
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

**Terminal 2 — the storefront** (http://localhost:5173)

```bash
cd web
npm install
cp .env.example .env
npm run dev
```

Open <http://localhost:5173>.

> **`.env.example` ships with Docker service names** (`DB_HOST=mysql`,
> `REDIS_HOST=redis`). Running outside Docker, change both to `127.0.0.1`. If
> Redis is not installed, set `CACHE_STORE=file` and `QUEUE_CONNECTION=database`.

### With Docker instead

```bash
cd api
cp .env.example .env
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

Five services: `nginx`, `app` (PHP-FPM), `mysql`, `redis`, `queue-worker`.

> **Not yet verified.** The Compose stack is written to spec but has never been
> built, because Docker was not installed on the development machine. The
> application itself is verified — 141 tests pass and the migrations and seeder
> run clean.

### Demo accounts

Password for all of them: `password`

| Role | Email |
| --- | --- |
| Admin | `admin@libyamarket.test` |
| Customer | `customer@libyamarket.test` |
| Seller | any address from `php artisan tinker` → `User::where('role','seller')->first()` |

The seeder creates 1 admin, 5 sellers, 21 customers, 6 categories and 75
products — deliberately including out-of-stock and inactive listings so the
unhappy paths have data too.

---

## Tests

```bash
cd api
php artisan test
```

```
Tests:    141 passed (429 assertions)
Duration: ~8s
```

26 unit tests (money, pricing, the order state machine — no database at all)
and 115 feature tests. Roughly half the suite covers **failures**: 403s, 422s,
409s, rollbacks and race conditions. A suite that only tests success proves the
code works when nothing goes wrong, which is the least interesting case.

The one to read is `a_mid_transaction_failure_rolls_back_every_write`. It
injects a failure while the *second* order line is being written — after the
order row, the first line and the first stock decrement have already been
issued — and asserts every one of those writes disappeared.

> **Known limitation, stated honestly:** the suite runs on in-memory SQLite,
> where `lockForUpdate()` is a no-op. It proves the *logic* of oversell
> prevention, not the *blocking behaviour* of the lock. For that, run the suite
> against the Compose MySQL service. This is documented rather than glossed over.

---

## Features

**Customer** — browse and search without an account, one persistent cart,
atomic checkout, order history, self-service cancellation inside a 60-minute
window, reviews gated on verified purchase.

**Seller** — full CRUD over their own inventory, orders containing their
products, fulfilment status transitions, sales statistics. A seller can never
read or write another seller's data; the rule is defined once, in
`ProductPolicy`, and every write path routes through it.

**Admin** — platform-wide orders and statistics, category management. Notably
an admin **cannot** edit someone else's cart: `CartItemPolicy` deliberately has
no `before()` bypass.

---

## Documentation

Nineteen documents in [`api/docs/`](api/docs/README.md), one per feature. Each
covers what it does, where the code lives, the design decisions **and why**, and
which tests prove it.

Start with these four — they carry the actual engineering weight:

1. [Orders & Atomic Checkout](api/docs/06-orders-checkout.md)
2. [Concurrency & Inventory](api/docs/07-concurrency-inventory.md)
3. [Money & Pricing](api/docs/08-money-pricing.md)
4. [Caching & Invalidation](api/docs/09-caching.md)

Also worth reading:

- [Frontend Blueprint](api/docs/18-frontend-blueprint.md) — the plan the SPA was built from
- [Production Gaps (عربي)](api/docs/19-production-gaps-ar.md) — an audited list of what is still missing
- [Setup guide (عربي)](api/docs/setup-ar.md) — installation and troubleshooting in Arabic

---

## Project status

**This is a portfolio and engineering-demonstration project. It is not ready to
take real money**, and the reasons are documented rather than hidden.

Missing, and known to be missing:

- **No payment gateway.** A search for any payment integration returns only
  `DB::transaction`. The `orders` table has no payment columns at all.
- **No password reset endpoints.** The config and the `password_reset_tokens`
  table exist; the routes do not.
- No email verification, no shipping addresses, no product images.

The full audit, with severity, evidence and a nine-step ordered roadmap, is in
[19-production-gaps-ar.md](api/docs/19-production-gaps-ar.md).

The hard parts — concurrency, inventory integrity, transactional atomicity,
financial precision — are done. What remains is known work, not open
engineering questions.

---

## Licence

MIT
