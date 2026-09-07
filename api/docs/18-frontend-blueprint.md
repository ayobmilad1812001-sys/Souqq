# 18 — Frontend Blueprint (Option A: separate SPA)

A build plan for a standalone single-page app that consumes the LibyaMarket API.
**You build it; this document is the map.** Every phase has concrete tasks and a
"done when" line so you always know whether you can move on.

The API is **not modified** by this work, except one CORS change in Phase 0.

---

## 0. The stack, and why

| Layer | Pick | Why this one |
| --- | --- | --- |
| Framework | **React 19 + TypeScript** | Largest hiring pool and ecosystem. Vue 3 is an equally good choice — see the note below. |
| Build tool | **Vite** | Instant dev server, first-class TS, the default everyone uses. |
| Styling | **Tailwind CSS v4** | You asked for it. v4 needs no `tailwind.config.js`; configuration lives in CSS. |
| Server state | **TanStack Query v5** | This is the important one. See §0.1. |
| Client state | **Zustand** | Auth token + user only. Small enough that Redux would be overkill. |
| Routing | **React Router v7** | Nested layouts and route-level guards. |
| Forms | **React Hook Form + Zod** | Zod schemas mirror the Form Request rules, so you catch errors before a round trip. |
| HTTP | **`fetch`** in a thin wrapper | Axios adds a dependency for something 60 lines of code does better here. |

> **If you prefer Vue:** swap React Router → Vue Router, TanStack Query React →
> `@tanstack/vue-query` (same API), Zustand → Pinia, React Hook Form → VeeValidate.
> **Everything in §2 (the API client) is framework-agnostic and unchanged.** That
> layer is ~70% of the value of this blueprint.

### 0.1 Why TanStack Query is not optional

Your API is a *server state* API: products, cart and orders live on the server and
change independently of the UI. Hand-rolling that with `useEffect` + `useState`
means reimplementing caching, deduplication, refetch-on-focus, loading and error
states, and cache invalidation after mutations — badly, in every component.

The single most valuable thing it gives you here: **after a mutation, invalidate a
query key and every screen showing that data updates itself.** Place an order →
invalidate `['cart']` and `['orders']` → the header cart badge, the cart page and
the order list all refresh with no manual wiring.

---

## 1. Screen inventory

Build in this order. Each row maps to endpoints you already have.

### Public

| Screen | Route | Endpoints |
| --- | --- | --- |
| Catalogue | `/` | `GET /products` |
| Product detail | `/products/:id` | `GET /products/:id`, `GET /products/:id/reviews` |
| Login | `/login` | `POST /login` |
| Register | `/register` | `POST /register` |

### Customer (auth required)

| Screen | Route | Endpoints |
| --- | --- | --- |
| Cart | `/cart` | `GET /cart`, `POST/PATCH/DELETE /cart/items` |
| Checkout | `/checkout` | `POST /orders` |
| My orders | `/orders` | `GET /orders` |
| Order detail | `/orders/:id` | `GET /orders/:id`, `PATCH /orders/:id/cancel` |
| Write a review | on product detail | `POST /products/:id/reviews` |

### Seller (`role:seller`)

| Screen | Route | Endpoints |
| --- | --- | --- |
| Dashboard | `/seller` | `GET /stats` |
| My inventory | `/seller/products` | `GET /products?mine=1` |
| Create / edit product | `/seller/products/:id?` | `POST/PATCH/DELETE /products` |
| Orders to fulfil | `/seller/orders` | `GET /orders`, `PATCH /orders/:id/status` |

### Admin (`role:admin`)

| Screen | Route | Endpoints |
| --- | --- | --- |
| Platform stats | `/admin` | `GET /stats` |
| Categories | `/admin/categories` | `POST/PATCH/DELETE /categories` |
| All orders | `/admin/orders` | `GET /orders`, `PATCH /orders/:id/status` |

---

## Phase 0 — Prepare the API (30 minutes, in the Laravel repo)

Your API currently has **no `config/cors.php`**, so it falls back to the framework
default: `allowed_origins: ["*"]`, `supports_credentials: false`.

That works for local development with Bearer tokens. It is **not** what you want in
production.

**Task 0.1** — publish and lock down the CORS config:

```bash
php artisan config:publish cors
```

Then edit `config/cors.php`:

```php
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],

    // Explicit origins. Never '*' in production.
    'allowed_origins' => array_filter(explode(',', (string) env('FRONTEND_URLS', 'http://localhost:5173'))),

    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 60 * 60 * 24,

    // Bearer tokens are sent in a header, not a cookie. Keep this false —
    // true would require exact-origin matching and cookie handling you do not need.
    'supports_credentials' => false,
];
```

Add to `.env`:

```env
FRONTEND_URLS=http://localhost:5173
```

**Done when:** `curl -H "Origin: http://localhost:5173" -X OPTIONS http://localhost:8000/api/v1/products -i` returns an `Access-Control-Allow-Origin` header.

> **Nothing else in the API needs to change.** Token auth, the response envelope
> and the error contract are already SPA-ready. Resist the urge to add endpoints
> "for the frontend" — if you need one, that is a signal to re-read the contract
> first.

---

## Phase 1 — Scaffold

```bash
npx create-vite@latest web --template react-ts
cd web
npm install
npm install @tanstack/react-query react-router-dom zustand react-hook-form zod @hookform/resolvers
npm install -D tailwindcss @tailwindcss/vite
```

`vite.config.ts`:

```ts
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
  plugins: [react(), tailwindcss()],
})
```

`src/index.css`:

```css
@import "tailwindcss";
```

`.env`:

```env
VITE_API_URL=http://localhost:8000/api/v1
```

### Folder structure

```
src/
├── api/
│   ├── client.ts          ← Phase 2. The whole contract lives here.
│   ├── types.ts           ← TypeScript mirrors of the API resources
│   └── endpoints/         ← one file per resource: products.ts, cart.ts, orders.ts…
├── components/
│   ├── ui/                ← Button, Input, Card, Badge, Spinner
│   └── layout/            ← Header, Nav, Footer
├── features/
│   ├── auth/
│   ├── catalog/
│   ├── cart/
│   ├── orders/
│   └── seller/
├── hooks/
├── lib/
│   ├── money.ts           ← Phase 2. Read §2.3 before writing this.
│   └── queryKeys.ts
├── stores/
│   └── auth.ts
└── routes/
```

**Done when:** `npm run dev` serves a Tailwind-styled page at `localhost:5173`.

---

## Phase 2 — The API client (the most important phase)

Do not start on screens until this is finished. Everything else depends on it.

### 2.1 The response envelope

Every response from your API has this shape:

```json
{ "success": true, "data": {}, "message": "Operation successful." }
```

Paginated responses add `meta` and `links` **alongside** `data`, not inside it:

```json
{
  "success": true,
  "data": [ ... ],
  "message": "Products retrieved.",
  "meta":  { "current_page": 1, "total": 70, "per_page": 15, "last_page": 5 },
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." }
}
```

**Unwrap it once, in the client.** No component should ever type `response.data.data`.

### 2.2 The client

```ts
// src/api/client.ts
const BASE = import.meta.env.VITE_API_URL

export class ApiError extends Error {
  constructor(
    public status: number,
    message: string,
    public errors: Record<string, unknown> = {},
  ) {
    super(message)
  }

  /** 422 — the payload was wrong. Retrying identically will fail identically. */
  get isValidation() { return this.status === 422 }

  /** 409 — the payload was fine but conflicts with server state. A retry may work. */
  get isConflict()   { return this.status === 409 }

  get isUnauthorized() { return this.status === 401 }
  get isForbidden()    { return this.status === 403 }
  get isRateLimited()  { return this.status === 429 }

  /** Field errors shaped for React Hook Form's setError. */
  fieldErrors(): Record<string, string> {
    const out: Record<string, string> = {}
    for (const [field, messages] of Object.entries(this.errors)) {
      if (Array.isArray(messages) && typeof messages[0] === 'string') {
        out[field] = messages[0]
      }
    }
    return out
  }
}

type Envelope<T> = {
  success: boolean
  data: T
  message: string
  meta?: PaginationMeta
  links?: PaginationLinks
}

export async function api<T>(
  path: string,
  options: RequestInit = {},
): Promise<{ data: T; message: string; meta?: PaginationMeta }> {
  const token = localStorage.getItem('token')

  const response = await fetch(`${BASE}${path}`, {
    ...options,
    headers: {
      'Accept': 'application/json',
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...options.headers,
    },
  })

  const body: Envelope<T> = await response.json().catch(() => ({
    success: false, data: null as T, message: 'Network error.',
  }))

  if (!response.ok) {
    // 401 anywhere means the token is dead. Clear it and bounce to login.
    if (response.status === 401) {
      localStorage.removeItem('token')
      window.location.href = '/login'
    }

    throw new ApiError(
      response.status,
      body.message ?? 'Request failed.',
      (body as { errors?: Record<string, unknown> }).errors ?? {},
    )
  }

  return { data: body.data, message: body.message, meta: body.meta }
}
```

### 2.3 Money — read this before writing a single price to the screen

**Your API returns every monetary value as a JSON *string*:**

```json
{ "price": "366.33", "subtotal": "200.00", "total": "215.00" }
```

That is deliberate. A JSON *number* would be parsed by JavaScript into a
double-precision float, and `0.1 + 0.2 !== 0.3`. The API goes to considerable
trouble to keep money exact (see [08-money-pricing.md](08-money-pricing.md)); do
not throw that away in the last 10 metres.

**Rule: never `parseFloat` a price to do arithmetic with it.**

```ts
// src/lib/money.ts

/** "366.33" -> 36633. Integer minor units, mirroring the API's Money class. */
export function toMinor(decimal: string): number {
  const [whole, fraction = ''] = decimal.split('.')
  return Number(whole) * 100 + Number(fraction.padEnd(2, '0').slice(0, 2))
}

/** 36633 -> "366.33" */
export function toDecimal(minor: number): string {
  const sign = minor < 0 ? '-' : ''
  const abs = Math.abs(minor)
  return `${sign}${Math.floor(abs / 100)}.${String(abs % 100).padStart(2, '0')}`
}

/** Display only. Never feed the output of this back into a calculation. */
export function formatPrice(decimal: string, currency = 'LYD'): string {
  return new Intl.NumberFormat('en-LY', {
    style: 'currency',
    currency,
    minimumFractionDigits: 2,
  }).format(Number(decimal))
}
```

If you only ever **display** prices the API already computed, you barely need this.
The moment you compute anything client-side ("you save X", "spend Y more for free
delivery"), do it in minor units.

> The cart endpoint already returns `subtotal`, `estimated_shipping` and
> `estimated_total`, so the cart screen needs **zero** client-side money maths.
> Prefer the server's numbers every time they exist.

### 2.4 Types

Mirror the API resources exactly. Note which fields are **optional** — your
Resources use `whenLoaded` / `whenCounted` / `when`, so those keys are *absent*,
not null, when not requested.

```ts
// src/api/types.ts
export type Role = 'customer' | 'seller' | 'admin'

export type OrderStatus =
  | 'pending' | 'confirmed' | 'processing'
  | 'shipped' | 'delivered' | 'cancelled'

export interface User {
  id: number
  name: string
  email: string
  role: Role
  created_at: string
}

export interface Category {
  id: number
  name: string
  slug: string
  products_count?: number      // only with withCount
  created_at: string
}

export interface Product {
  id: number
  name: string
  description: string
  price: string                // ← string, not number
  sku: string
  stock_quantity: number
  in_stock: boolean
  is_active: boolean
  category?: Category          // omitted if not eager loaded
  seller?: { id: number; name: string }
  reviews_count?: number       // only with withCount
  average_rating?: string      // ← detail endpoint ONLY. Absent on listings.
  created_at: string
  updated_at: string
}

export interface CartItem {
  id: number
  quantity: number
  unit_price: string
  line_total: string
  product?: Product
}

export interface Cart {
  id: number
  items: CartItem[]
  items_count: number
  total_quantity: number
  subtotal: string
  estimated_shipping: string
  estimated_total: string
  updated_at: string
}

export interface Order {
  id: number
  status: OrderStatus
  allowed_transitions: OrderStatus[]   // ← drive your UI from this
  subtotal: string
  shipping_cost: string
  total: string
  items?: OrderItem[]
  items_count?: number
  customer?: User
  cancelled_at: string | null
  created_at: string
}

export interface OrderItem {
  id: number
  product_id: number
  quantity: number
  unit_price: string           // historical price, not today's
  subtotal: string
  product?: Product
}

export interface PaginationMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
}
```

**Done when:** you can call `api<Product[]>('/products')` in a scratch component and log a typed array.

---

## Phase 3 — Auth

### Token storage: pick with your eyes open

`localStorage` is what this blueprint uses. It survives refresh and is simple.
It is also **readable by any XSS on your origin**.

The alternative — keeping the token in memory only — means a page refresh logs the
user out, and it does **not** actually stop a determined XSS (which can just call
your API directly while the page is open). The real defence against XSS is not
storing the token differently; it is not having XSS: React escapes by default,
so never use `dangerouslySetInnerHTML` on anything a user typed.

`localStorage` is the right trade-off here. Know why you chose it.

> Sanctum tokens in this API **do not expire** (`SANCTUM_TOKEN_EXPIRATION` is
> unset), so there is no refresh-token flow to build. If you later set an
> expiry, a 401 already redirects to login — that path is handled.

### Tasks

- `stores/auth.ts` — Zustand store holding `token` and `user`, hydrated from `localStorage` on boot.
- `POST /login` and `POST /register` both return `{ user, token }` in `data`. Store both.
- `GET /user` on app boot to validate a stored token; a 401 clears it automatically.
- `<ProtectedRoute>` — redirects to `/login` when there is no token.
- `<RoleRoute roles={['seller','admin']}>` — 403 page otherwise.
- `POST /logout` — revokes **only the current token**, so other devices stay signed in.

### Login is rate limited at 6/min

Keyed on **email + IP**. Handle 429 explicitly — show "too many attempts, wait a
minute", not a generic error. Checkout is limited to 10/min the same way.

**Done when:** you can register, land authenticated, refresh the page and stay signed in, and log out.

---

## Phase 4 — Catalogue

The read path. Most of your traffic, so get the caching right.

### Query keys

```ts
// src/lib/queryKeys.ts
export const keys = {
  products: (filters: Record<string, unknown>) => ['products', filters] as const,
  product:  (id: number) => ['products', id] as const,
  reviews:  (id: number) => ['products', id, 'reviews'] as const,
  categories: ['categories'] as const,
  cart: ['cart'] as const,
  orders: (filters: Record<string, unknown>) => ['orders', filters] as const,
  order: (id: number) => ['orders', id] as const,
  stats: ['stats'] as const,
}
```

Including `filters` in the key means each filter combination caches separately —
mirroring how the API caches listings per filter set.

### Supported query parameters

| Param | Notes |
| --- | --- |
| `search` | matches name **or** description |
| `category` | accepts an **id or a slug** — use the slug in URLs |
| `min_price`, `max_price` | `max_price` must be ≥ `min_price`, else 422 |
| `in_stock` | `1` to hide sold-out items |
| `mine` | `1` — seller's own inventory, **including inactive**. Requires auth. |
| `sort` | **whitelisted**: `price_asc`, `price_desc`, `name_asc`, `name_desc`, `newest`, `oldest`. Anything else is a 422. |
| `per_page` | **capped at 100** by the API |
| `page` | 1-indexed |

### Tasks

- Mirror filters into the URL query string, so a filtered catalogue is shareable and the back button works.
- Debounce the search input ~300ms.
- `average_rating` is **absent** on the listing endpoint and present on detail. Your card component must not assume it.
- Build the skeleton loader before the happy path. It is much harder to retrofit.

**Done when:** you can search, filter by category and price, sort, page through results, and the browser back button restores the previous filter state.

---

## Phase 5 — Cart

### The one thing that makes this phase easy

**Every cart endpoint returns the complete cart with recomputed totals** — `GET`,
`POST`, `PATCH` and `DELETE` alike.

So do not refetch after a mutation. Write the response straight into the cache:

```ts
const addItem = useMutation({
  mutationFn: (body: { product_id: number; quantity: number }) =>
    api<Cart>('/cart/items', { method: 'POST', body: JSON.stringify(body) }),

  // The response IS the new cart. One round trip, not two.
  onSuccess: ({ data }) => queryClient.setQueryData(keys.cart, data),
})
```

### Rules to encode in the UI

- Adding the same product twice **accumulates** into one line. Do not render duplicates.
- `PATCH` sets an **absolute** quantity, not a delta. Sending `{quantity: 4}` means "make it 4".
- Removing a line is `DELETE`, not `PATCH` with `quantity: 0` (which is a 422).
- Max quantity per line is **100**.
- The cart's stock check is **advisory** — the authoritative one happens at checkout under a row lock. So an item can be in the cart and still fail at checkout. **Design for that**; it is not an edge case, it is the normal race.

### Handling a 409 on add

```ts
if (error instanceof ApiError && error.isConflict) {
  const { requested, available } = error.errors as { requested: number; available: number }
  // "Only 1 left — add 1 instead?"  Do not just print error.message.
}
```

The API gives you machine-readable stock figures precisely so you can build that.

**Done when:** add, accumulate, change quantity, remove, and a sold-out add shows a useful message with the real available count.

---

## Phase 6 — Checkout & orders

### Checkout

`POST /orders` with **no body** — it reads the authenticated user's cart.

Failure modes you must handle, all of which are normal:

| Status | Meaning | UI |
| --- | --- | --- |
| 422 | Cart empty, or a product was deactivated | Send them back to the cart |
| 409 | Stock ran out between carting and paying | Show which item and how many remain |
| 429 | More than 10 checkouts/min | "Please wait a moment" |

On success: invalidate `['cart']` **and** `['orders']`, then navigate to the order.

> **Disable the submit button on first click.** The API is transactional and safe,
> but a double-click is two orders, and that is a support ticket.

### Order status

Never hard-code the state machine in the frontend. The API tells you what is legal:

```json
{ "status": "pending", "allowed_transitions": ["confirmed", "cancelled"] }
```

```tsx
{order.allowed_transitions.map(status => (
  <button key={status} onClick={() => advance(status)}>
    Mark as {status}
  </button>
))}
```

An empty array means the order is terminal (`delivered` or `cancelled`). Render no
buttons. This is why the field exists — a hard-coded frontend state machine drifts
out of sync with the server the first time the rules change.

### Cancellation

Two separate endpoints with different rules:

- `PATCH /orders/:id/cancel` — the **customer** path. Only from `pending` or `confirmed`, and only within **60 minutes** of placing the order. Show the remaining window, and hide the button once it closes.
- `PATCH /orders/:id/status` with `cancelled` — **admin only**. A seller cannot cancel, because an order may contain another seller's goods.

**Done when:** a customer can place an order, see it, cancel it inside the window, and is refused outside it with a clear message.

---

## Phase 7 — Seller & admin

The same `GET /orders` endpoint returns different rows depending on who asks — the
API scopes it in the query. Your frontend does **no filtering**; just render what
you get.

`GET /stats` likewise returns two different shapes, distinguished by a `scope` field:

```ts
if (stats.scope === 'seller') { /* products_total, units_sold, gross_revenue */ }
else                          { /* users_total, orders_by_status, … */ }
```

Branch on `scope`, not on the presence of keys.

### Seller product form

Mirror the server's validation in Zod so users get instant feedback:

```ts
const productSchema = z.object({
  name: z.string().min(1).max(255),
  description: z.string().min(1),
  // decimal:0,2 on the server — matches DECIMAL(10,2) exactly
  price: z.string().regex(/^\d+(\.\d{1,2})?$/, 'Use at most 2 decimal places'),
  sku: z.string().min(1).max(64),
  stock_quantity: z.number().int().min(0),
  category_id: z.number().int(),
  is_active: z.boolean().optional(),
})
```

**Client validation is a convenience, never a guarantee.** Always render the
server's 422 `errors` too — the server is the authority, and a 422 you did not
predict is a bug in your schema, not in the API.

There is no `seller_id` field. Ownership comes from the token; sending one is ignored.

**Done when:** a seller can CRUD their own products, sees only their own stats, and gets a 403 page on another seller's product.

---

## Phase 8 — Production

- `npm run build`, deploy `dist/` as static files (Netlify, Vercel, nginx).
- Set `VITE_API_URL` to the real API URL at build time — Vite inlines it, so it is **baked into the bundle**. It is public; never put a secret in a `VITE_` variable.
- Add the production origin to `FRONTEND_URLS` in the API's `.env`.
- Add an error boundary and a 404 route.
- Check a mobile viewport. A marketplace is mostly phones.

**Done when:** the deployed SPA completes a full purchase against the deployed API.

---

## The nine gotchas, collected

Pin this list somewhere visible.

1. **Prices are strings.** Never `parseFloat` to calculate. Use minor units.
2. **`data` is unwrapped once, in the client.** Nothing else touches the envelope.
3. **`meta` and `links` sit beside `data`,** not inside it.
4. **422 ≠ 409.** 422 = your payload is wrong. 409 = state conflict, a retry may work.
5. **Optional fields are absent, not null** — `whenLoaded`, `whenCounted`, `when`. `average_rating` only exists on the product **detail** endpoint.
6. **Cart mutations return the whole cart.** Write it to the cache; do not refetch.
7. **`allowed_transitions` drives the status UI.** Never hard-code the state machine.
8. **The cart stock check is advisory.** Checkout can still 409. That is normal, not an edge case.
9. **Rate limits are real:** 6/min on login, 10/min on checkout, 120/min otherwise. Handle 429 with a specific message.

---

## Suggested order of work

| Phase | What | Rough effort |
| --- | --- | --- |
| 0 | CORS in the API | 30 min |
| 1 | Scaffold | 1 h |
| 2 | **API client, types, money** | **half a day** |
| 3 | Auth + guarded routes | half a day |
| 4 | Catalogue + filters | 1 day |
| 5 | Cart | half a day |
| 6 | Checkout + orders | 1 day |
| 7 | Seller + admin | 1–2 days |
| 8 | Production | half a day |

**Phase 2 is the one to take slowly.** Every later phase is short *because* of it.
If you find yourself writing `response.data.data`, or calling `parseFloat` on a
price, stop and fix Phase 2 instead of working around it.

---

## Related documents

- [14-api-responses-errors.md](14-api-responses-errors.md) — the envelope and every status code
- [08-money-pricing.md](08-money-pricing.md) — why prices are strings
- [06-orders-checkout.md](06-orders-checkout.md) — the state machine and cancellation rules
- [02-authorization-rbac.md](02-authorization-rbac.md) — who may do what
- [05-cart.md](05-cart.md) — advisory vs authoritative stock checks
