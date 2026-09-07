import { useState } from 'react'
import { Link } from 'react-router-dom'

import type { OrderStatus } from '@/api/types'
import { Alert } from '@/components/ui/Alert'
import { OrderStatusBadge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card, CardBody } from '@/components/ui/Card'
import { EmptyState } from '@/components/ui/EmptyState'
import { Select } from '@/components/ui/Input'
import { Pagination } from '@/components/ui/Pagination'
import { PageSpinner } from '@/components/ui/Spinner'
import { errorMessage } from '@/hooks/useAuth'
import { useOrders } from '@/hooks/useOrders'
import { formatPrice } from '@/lib/money'
import { useRole } from '@/stores/auth'

const STATUSES: OrderStatus[] = [
  'pending',
  'confirmed',
  'processing',
  'shipped',
  'delivered',
  'cancelled',
]

export function OrdersPage() {
  const role = useRole()
  const [status, setStatus] = useState<OrderStatus | ''>('')
  const [page, setPage] = useState(1)

  const { data, isPending, error } = useOrders({
    status: status || undefined,
    page,
    per_page: 10,
  })

  // The API scopes this list by role in the query itself, so there is nothing
  // to filter client-side; the heading just names what the viewer is seeing.
  const heading =
    role === 'admin' ? 'All orders' : role === 'seller' ? 'Orders to fulfil' : 'My orders'

  if (isPending) return <PageSpinner />
  if (error) return <Alert tone="error">{errorMessage(error)}</Alert>

  const orders = data.data

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
        <h1 className="text-2xl font-bold">{heading}</h1>

        <div className="w-56">
          <Select
            label="Filter by status"
            value={status}
            onChange={(event) => {
              setStatus(event.target.value as OrderStatus | '')
              setPage(1)
            }}
          >
            <option value="">All statuses</option>
            {STATUSES.map((value) => (
              <option key={value} value={value} className="capitalize">
                {value}
              </option>
            ))}
          </Select>
        </div>
      </div>

      {orders.length === 0 ? (
        <EmptyState
          title="No orders yet"
          description={
            role === 'customer' ? 'Once you place an order it will appear here.' : undefined
          }
          action={
            role === 'customer' ? (
              <Link to="/">
                <Button>Start shopping</Button>
              </Link>
            ) : undefined
          }
        />
      ) : (
        <>
          <div className="space-y-3">
            {orders.map((order) => (
              <Card key={order.id}>
                <CardBody className="flex flex-wrap items-center gap-4">
                  <div className="min-w-40 flex-1">
                    <Link
                      to={`/orders/${order.id}`}
                      className="font-medium hover:text-violet-700"
                    >
                      Order #{order.id}
                    </Link>
                    <p className="text-sm text-muted">
                      {new Date(order.created_at).toLocaleDateString()}
                      {order.items_count !== undefined && ` \u00b7 ${order.items_count} items`}
                      {order.customer && ` \u00b7 ${order.customer.name}`}
                    </p>
                  </div>

                  <OrderStatusBadge status={order.status} />

                  <span className="w-28 text-right font-semibold">{formatPrice(order.total)}</span>
                </CardBody>
              </Card>
            ))}
          </div>

          {data.meta && (
            <div className="mt-6">
              <Pagination meta={data.meta} onPageChange={setPage} />
            </div>
          )}
        </>
      )}
    </div>
  )
}
