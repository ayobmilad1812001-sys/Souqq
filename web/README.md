# Souqa Web

A standalone React SPA for the [Souqa API](../api) (the Laravel app in `api/`). Built to the
plan in `api/docs/18-frontend-blueprint.md`.

The API is a separate application. This app talks to it over HTTP with a
Sanctum Bearer token and shares no code with it.

## Running

The API must be running first:

```bash
cd ../api
php artisan serve
```

Then:

```bash
npm install
cp .env.example .env
npm run dev
```

Open <http://localhost:5173>.

| Account | Password |
| --- | --- |
| `customer@libyamarket.test` | `password` |
| `admin@libyamarket.test` | `password` |

## Scripts

```bash
npm run dev       # dev server on :5173
npm run build     # type-check then bundle to dist/
npm run preview   # serve the built bundle
npm run lint      # oxlint
```

## Stack

| Layer | Choice |
| --- | --- |
| Framework | React 19 + TypeScript |
| Build | Vite 8 |
| Styling | Tailwind CSS v4 (configured in CSS, no `tailwind.config.js`) |
| Server state | TanStack Query v5 |
| Client state | Zustand (auth only) |
| Routing | React Router v7 |
| Forms | React Hook Form + Zod |
| HTTP | `fetch`, wrapped in `src/api/client.ts` |

## Structure

```
src/
├── api/
│   ├── client.ts        the ONE place the response envelope is unwrapped
│   ├── types.ts         mirrors of the API's Resource classes
│   └── endpoints/       one module per resource
├── components/
│   ├── ui/              Button, Input, Card, Badge, Alert, Spinner, Pagination
│   └── layout/          Header, AppLayout
├── features/
│   ├── auth/            login, register
│   ├── catalog/         listing, filters, product detail, reviews
│   ├── cart/            cart and checkout
│   ├── orders/          order list and detail
│   ├── seller/          stats, inventory, product form
│   └── admin/           categories
├── hooks/               one per domain: useAuth, useCatalog, useCart, useOrders
├── lib/                 money.ts, queryKeys.ts, cn.ts
├── routes/              ProtectedRoute, RoleRoute
└── stores/auth.ts
```

## Things worth knowing before you change anything

**1. Prices are strings, and stay strings.**
The API sends `"366.33"`, never `366.33`. A JSON number would be parsed into a
float and `0.1 + 0.2 !== 0.3`. `src/lib/money.ts` does all arithmetic in integer
minor units. Never `parseFloat` a price in order to calculate with it.

**2. The envelope is unwrapped exactly once.**
`api()` in `src/api/client.ts` turns `{ success, data, message, meta }` into
`{ data, message, meta }`. No component should ever write `response.data.data`.

**3. Cart mutations write straight to the cache.**
Every cart endpoint returns the complete cart with recomputed totals, so
`useCartMutation` calls `setQueryData` instead of refetching. One round trip.

**4. Order actions come from `allowed_transitions`.**
The server sends the legal next states with every order. The UI renders a
button per entry and nothing when the array is empty. The state machine is
never duplicated here.

**5. 409 is not 422.**
422 means the payload was wrong. 409 means it conflicted with server state —
stock ran out, an illegal transition, the cancellation window closed. A 409 on
add-to-cart carries `requested` and `available`, and the product page offers to
add the available quantity instead.

**6. The cart's stock check is advisory.**
Checkout can still fail with a 409 even though the item sat happily in the cart.
That is the normal race, not an edge case, and the cart page handles it.

**7. Rate limits are real.**
6/min on login (keyed on email + IP), 10/min on checkout, 120/min otherwise.
`ApiError.isRateLimited` exists so these get a specific message.

## Production

```bash
npm run build
```

Deploy `dist/` as static files. Set `VITE_API_URL` at **build** time — Vite
inlines it into the bundle, so it is public; never put a secret in a `VITE_`
variable.

Add the deployed origin to `FRONTEND_URLS` in the API's `.env`, which drives the
CORS allow-list in `config/cors.php`.
