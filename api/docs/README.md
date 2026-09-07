# LibyaMarket — Feature Documentation

One document per feature. Each covers **what it does**, **where the code lives**, **the design decisions and why**, and **which tests prove it**.

| # | Document | Feature |
| --- | --- | --- |
| 01 | [Authentication](01-authentication.md) | Registration, login, Sanctum tokens, throttling |
| 02 | [Authorization & RBAC](02-authorization-rbac.md) | Roles, policies, multi-tenant seller isolation |
| 03 | [Product Catalog](03-products-catalog.md) | CRUD, search, filtering, sorting, pagination |
| 04 | [Categories](04-categories.md) | Platform-owned taxonomy, slugs, delete protection |
| 05 | [Shopping Cart](05-cart.md) | One cart per customer, quantity accumulation, stock guards |
| 06 | [Orders & Atomic Checkout](06-orders-checkout.md) | The transaction, the state machine, cancellation |
| 07 | [Concurrency & Inventory](07-concurrency-inventory.md) | Pessimistic locking, oversell prevention, ledger |
| 08 | [Money & Pricing](08-money-pricing.md) | Integer minor units, DECIMAL, shipping rules |
| 09 | [Caching & Invalidation](09-caching.md) | Redis read-through, versioned listing namespace |
| 10 | [Queues & Background Jobs](10-queues-jobs.md) | Redis queue, retries, failure handling |
| 11 | [Events & Listeners](11-events-listeners.md) | Domain events, decoupled side effects |
| 12 | [Product Reviews](12-reviews.md) | Verified-purchaser rule, ratings |
| 13 | [Statistics](13-statistics.md) | Seller and platform dashboards |
| 14 | [API Responses & Errors](14-api-responses-errors.md) | The envelope, domain exceptions, status codes |
| 15 | [Database Schema](15-database-schema.md) | Tables, indexes, foreign key strategy |
| 16 | [Testing](16-testing.md) | 141 tests, what each suite proves |
| 17 | [Docker Infrastructure](17-docker-infrastructure.md) | Five services, build layers, healthchecks |
| 18 | [Frontend Blueprint](18-frontend-blueprint.md) | Build plan for a separate SPA that consumes this API |
| 19 | [Production Gaps (عربي)](19-production-gaps-ar.md) | Audited list of what is still missing before this can go live |

## Reading order

If you are reviewing this codebase for the first time, the four documents that
carry the actual engineering weight are:

1. [Orders & Atomic Checkout](06-orders-checkout.md)
2. [Concurrency & Inventory](07-concurrency-inventory.md)
3. [Money & Pricing](08-money-pricing.md)
4. [Caching & Invalidation](09-caching.md)

Everything else is competent plumbing around those four.

If you are about to build the frontend, start at
[18-frontend-blueprint.md](18-frontend-blueprint.md) and read 14, 08 and 06 alongside it.

## Layer discipline

Every feature below obeys the same rule about where code is allowed to live:

```
Form Request  ->  validation only, no business rules
Controller    ->  HTTP only, 3-6 lines, no try/catch
Policy        ->  who may do this to this record
Service       ->  all business logic, transactions
Model         ->  persistence, relationships, query scopes
Observer      ->  cache invalidation on every write path
Resource      ->  output shaping
```

If you find business logic in a controller, or an HTTP status inside a service,
that is a bug in the design, not a style preference.

## Arabic documents

- [setup-ar.md](setup-ar.md) — installation and troubleshooting
- [19-production-gaps-ar.md](19-production-gaps-ar.md) — what is still missing before production
