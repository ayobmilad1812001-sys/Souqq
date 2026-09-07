import { Alert } from '@/components/ui/Alert'
import { Card, CardBody } from '@/components/ui/Card'
import { PageSpinner } from '@/components/ui/Spinner'
import { errorMessage } from '@/hooks/useAuth'
import { useStats } from '@/hooks/useStats'
import { formatPrice } from '@/lib/money'

function Stat({ label, value }: { label: string; value: string | number }) {
  return (
    <Card>
      <CardBody>
        <dt className="text-sm text-muted">{label}</dt>
        <dd className="mt-1 text-2xl font-bold">{value}</dd>
      </CardBody>
    </Card>
  )
}

export function StatsPage() {
  const { data, isPending, error } = useStats()

  if (isPending) return <PageSpinner />
  if (error) return <Alert tone="error">{errorMessage(error)}</Alert>

  const stats = data.data

  // Branch on `scope`, not on which keys happen to be present.
  if (stats.scope === 'seller') {
    return (
      <div>
        <h1 className="mb-6 text-2xl font-bold">Your sales</h1>
        <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <Stat label="Products" value={stats.products_total} />
          <Stat label="Active listings" value={stats.products_active} />
          <Stat label="Out of stock" value={stats.out_of_stock} />
          <Stat label="Units sold" value={stats.units_sold} />
          <Stat label="Gross revenue" value={formatPrice(stats.gross_revenue)} />
        </dl>
      </div>
    )
  }

  return (
    <div>
      <h1 className="mb-6 text-2xl font-bold">Platform overview</h1>

      <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <Stat label="Users" value={stats.users_total} />
        <Stat label="Sellers" value={stats.sellers_total} />
        <Stat label="Products" value={stats.products_total} />
        <Stat label="Orders" value={stats.orders_total} />
        <Stat label="Gross revenue" value={formatPrice(stats.gross_revenue)} />
      </dl>

      <h2 className="mt-8 mb-3 text-lg font-semibold">Orders by status</h2>
      <dl className="grid gap-4 sm:grid-cols-3 lg:grid-cols-6">
        {Object.entries(stats.orders_by_status).map(([status, count]) => (
          <Card key={status}>
            <CardBody>
              <dt className="text-sm capitalize text-muted">{status}</dt>
              <dd className="mt-1 text-xl font-bold">{count}</dd>
            </CardBody>
          </Card>
        ))}
      </dl>
    </div>
  )
}
