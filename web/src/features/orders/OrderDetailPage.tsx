import { Link, useParams } from 'react-router-dom'

import { ApiError } from '@/api/client'
import { Alert } from '@/components/ui/Alert'
import { OrderStatusBadge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { EmptyState } from '@/components/ui/EmptyState'
import { PageSpinner } from '@/components/ui/Spinner'
import { errorMessage } from '@/hooks/useAuth'
import { useCancelOrder, useOrder, useUpdateOrderStatus } from '@/hooks/useOrders'
import { formatPrice } from '@/lib/money'
import { useRole } from '@/stores/auth'

export function OrderDetailPage() {
  const { id } = useParams<{ id: string }>()
  const orderId = Number(id)
  const role = useRole()

  const { data, isPending, error } = useOrder(orderId)
  const updateStatus = useUpdateOrderStatus()
  const cancelOrder = useCancelOrder()

  if (isPending) return <PageSpinner />

  if (error) {
    return (
      <EmptyState
        title={error instanceof ApiError && error.isNotFound ? 'Order not found' : 'Could not load this order'}
        description={errorMessage(error)}
        action={
          <Link to="/orders">
            <Button variant="secondary">Back to orders</Button>
          </Link>
        }
      />
    )
  }

  const order = data.data

  // Sellers and admins advance fulfilment; a seller may never cancel, because
  // an order can contain another seller's goods. The API enforces both -- we
  // only decide what to render.
  const canFulfil = role === 'seller' || role === 'admin'
  const fulfilmentOptions = order.allowed_transitions.filter(
    (status) => status !== 'cancelled' || role === 'admin',
  )

  const customerCanCancel =
    role === 'customer' && order.allowed_transitions.includes('cancelled')

  const mutationError = updateStatus.error ?? cancelOrder.error

  return (
    <div>
      <Link to="/orders" className="text-sm text-violet-700 hover:underline">
        &larr; Back to orders
      </Link>

      <div className="mt-4 mb-6 flex flex-wrap items-center gap-3">
        <h1 className="text-2xl font-bold">Order #{order.id}</h1>
        <OrderStatusBadge status={order.status} />
        <span className="text-sm text-muted">
          Placed {new Date(order.created_at).toLocaleString()}
        </span>
      </div>

      {mutationError && (
        <Alert tone="error" className="mb-4">
          {mutationError instanceof ApiError && mutationError.isConflict
            ? mutationError.message
            : errorMessage(mutationError)}
        </Alert>
      )}

      <div className="grid gap-6 lg:grid-cols-3">
        <div className="lg:col-span-2">
          <Card>
            <CardHeader>
              <h2 className="font-semibold">Items</h2>
            </CardHeader>
            <CardBody className="space-y-3">
              {order.items?.map((item) => (
                <div key={item.id} className="flex flex-wrap items-center gap-3">
                  <div className="min-w-40 flex-1">
                    <Link
                      to={`/products/${item.product_id}`}
                      className="font-medium hover:text-violet-700"
                    >
                      {item.product?.name ?? `Product #${item.product_id}`}
                    </Link>
                    {/* The frozen price, not today's catalogue price. */}
                    <p className="text-sm text-muted">
                      {item.quantity} &times; {formatPrice(item.unit_price)}
                    </p>
                  </div>
                  <span className="font-semibold">{formatPrice(item.subtotal)}</span>
                </div>
              ))}
            </CardBody>
          </Card>
        </div>

        <div className="space-y-4">
          <Card>
            <CardBody>
              <dl className="space-y-2 text-sm">
                <div className="flex justify-between">
                  <dt className="text-muted">Subtotal</dt>
                  <dd>{formatPrice(order.subtotal)}</dd>
                </div>
                <div className="flex justify-between">
                  <dt className="text-muted">Shipping</dt>
                  <dd>{formatPrice(order.shipping_cost)}</dd>
                </div>
                <div className="flex justify-between border-t border-line pt-2 text-base font-semibold">
                  <dt>Total</dt>
                  <dd>{formatPrice(order.total)}</dd>
                </div>
              </dl>
            </CardBody>
          </Card>

          {/* The buttons come from allowed_transitions, so the client never
              hard-codes the state machine and cannot drift from the server. */}
          {canFulfil && fulfilmentOptions.length > 0 && (
            <Card>
              <CardHeader>
                <h2 className="font-semibold">Update status</h2>
              </CardHeader>
              <CardBody className="flex flex-wrap gap-2">
                {fulfilmentOptions.map((status) => (
                  <Button
                    key={status}
                    size="sm"
                    variant={status === 'cancelled' ? 'danger' : 'primary'}
                    loading={updateStatus.isPending}
                    onClick={() => updateStatus.mutate({ id: order.id, status })}
                  >
                    Mark {status}
                  </Button>
                ))}
              </CardBody>
            </Card>
          )}

          {customerCanCancel && (
            <Card>
              <CardBody className="space-y-2">
                <Button
                  variant="danger"
                  className="w-full"
                  loading={cancelOrder.isPending}
                  onClick={() => cancelOrder.mutate(order.id)}
                >
                  Cancel this order
                </Button>
                <p className="text-xs text-muted">
                  Orders can be cancelled within 60 minutes, and only before they ship.
                </p>
              </CardBody>
            </Card>
          )}

          {order.allowed_transitions.length === 0 && (
            <Alert tone="info">This order is complete and can no longer change.</Alert>
          )}
        </div>
      </div>
    </div>
  )
}
