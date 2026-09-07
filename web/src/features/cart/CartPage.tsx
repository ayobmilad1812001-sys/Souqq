import { Link, useNavigate } from 'react-router-dom'

import { ApiError } from '@/api/client'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Card, CardBody } from '@/components/ui/Card'
import { EmptyState } from '@/components/ui/EmptyState'
import { PageSpinner } from '@/components/ui/Spinner'
import { errorMessage } from '@/hooks/useAuth'
import { useCart, useRemoveCartItem, useUpdateCartItem } from '@/hooks/useCart'
import { usePlaceOrder } from '@/hooks/useOrders'
import { formatPrice, isZeroMoney, subtractMoney } from '@/lib/money'

const FREE_SHIPPING_THRESHOLD = '500.00'

export function CartPage() {
  const { data, isPending, error } = useCart()
  const updateItem = useUpdateCartItem()
  const removeItem = useRemoveCartItem()
  const placeOrder = usePlaceOrder()
  const navigate = useNavigate()

  if (isPending) return <PageSpinner />
  if (error) return <Alert tone="error">{errorMessage(error)}</Alert>

  const cart = data.data

  if (cart.items.length === 0) {
    return (
      <EmptyState
        title="Your cart is empty"
        description="Browse the catalogue and add something you like."
        action={
          <Link to="/">
            <Button>Start shopping</Button>
          </Link>
        }
      />
    )
  }

  const conflict = placeOrder.error instanceof ApiError ? placeOrder.error.stockConflict() : null
  const shortfall = subtractMoney(FREE_SHIPPING_THRESHOLD, cart.subtotal)
  const qualifiesForFreeShipping = isZeroMoney(cart.estimated_shipping)

  return (
    <div>
      <h1 className="mb-6 text-2xl font-bold">Your cart</h1>

      <div className="grid gap-6 lg:grid-cols-3">
        <div className="space-y-3 lg:col-span-2">
          {cart.items.map((item) => (
            <Card key={item.id}>
              <CardBody className="flex flex-wrap items-center gap-4">
                <div className="min-w-48 flex-1">
                  <Link
                    to={`/products/${item.product?.id}`}
                    className="font-medium hover:text-violet-700"
                  >
                    {item.product?.name ?? 'Product'}
                  </Link>
                  <p className="text-sm text-muted">{formatPrice(item.unit_price)} each</p>
                </div>

                <label className="text-sm">
                  <span className="sr-only">Quantity</span>
                  <input
                    type="number"
                    min={1}
                    max={100}
                    defaultValue={item.quantity}
                    disabled={updateItem.isPending}
                    // PATCH takes an ABSOLUTE quantity, not a delta, so a
                    // retried request cannot double-count.
                    onBlur={(event) => {
                      const quantity = Math.max(1, Number(event.target.value))
                      if (quantity !== item.quantity) {
                        updateItem.mutate({ itemId: item.id, quantity })
                      }
                    }}
                    className="w-20 rounded-lg border border-line px-2 py-1"
                  />
                </label>

                <span className="w-28 text-right font-semibold">{formatPrice(item.line_total)}</span>

                <Button
                  variant="ghost"
                  size="sm"
                  loading={removeItem.isPending}
                  onClick={() => removeItem.mutate(item.id)}
                >
                  Remove
                </Button>
              </CardBody>
            </Card>
          ))}

          {(updateItem.error || removeItem.error) && (
            <Alert tone="error">{errorMessage(updateItem.error ?? removeItem.error)}</Alert>
          )}
        </div>

        <div>
          <Card className="lg:sticky lg:top-20">
            <CardBody className="space-y-3">
              <h2 className="font-semibold">Order summary</h2>

              <dl className="space-y-2 text-sm">
                <div className="flex justify-between">
                  <dt className="text-muted">Subtotal</dt>
                  <dd>{formatPrice(cart.subtotal)}</dd>
                </div>
                <div className="flex justify-between">
                  <dt className="text-muted">Shipping</dt>
                  <dd>
                    {qualifiesForFreeShipping ? 'Free' : formatPrice(cart.estimated_shipping)}
                  </dd>
                </div>
                <div className="flex justify-between border-t border-line pt-2 text-base font-semibold">
                  <dt>Total</dt>
                  <dd>{formatPrice(cart.estimated_total)}</dd>
                </div>
              </dl>

              {!qualifiesForFreeShipping && (
                <p className="text-xs text-muted">
                  Spend {formatPrice(shortfall)} more for free delivery.
                </p>
              )}

              {conflict && (
                <Alert tone="warning" title="Stock ran out">
                  Someone bought this while it was in your cart. Only {conflict.available} remain
                  &mdash; adjust the quantity and try again.
                </Alert>
              )}

              {placeOrder.error && !conflict && (
                <Alert tone="error">{errorMessage(placeOrder.error)}</Alert>
              )}

              <Button
                className="w-full"
                // Disabled while pending: a double-click here is two orders.
                loading={placeOrder.isPending}
                onClick={() =>
                  placeOrder.mutate(undefined, {
                    onSuccess: ({ data: order }) => navigate(`/orders/${order.id}`),
                  })
                }
              >
                Place order
              </Button>

              <p className="text-xs text-muted">
                Stock is confirmed at checkout, so an item can still sell out between now and then.
              </p>
            </CardBody>
          </Card>
        </div>
      </div>
    </div>
  )
}
