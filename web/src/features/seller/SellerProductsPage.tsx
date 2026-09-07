import { useState } from 'react'
import { Link } from 'react-router-dom'

import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card, CardBody } from '@/components/ui/Card'
import { EmptyState } from '@/components/ui/EmptyState'
import { Pagination } from '@/components/ui/Pagination'
import { PageSpinner } from '@/components/ui/Spinner'
import { errorMessage } from '@/hooks/useAuth'
import { useDeleteProduct, useProducts } from '@/hooks/useCatalog'
import { formatPrice } from '@/lib/money'

export function SellerProductsPage() {
  const [page, setPage] = useState(1)

  // `mine=1` returns this seller's own inventory INCLUDING inactive products.
  // The API never caches this view, because it is personalised.
  const { data, isPending, error } = useProducts({ mine: true, per_page: 15, page })
  const deleteProduct = useDeleteProduct()

  if (isPending) return <PageSpinner />
  if (error) return <Alert tone="error">{errorMessage(error)}</Alert>

  const products = data.data

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-bold">My inventory</h1>
        <Link to="/seller/products/new">
          <Button>Add product</Button>
        </Link>
      </div>

      {deleteProduct.error && (
        <Alert tone="error" className="mb-4">
          {errorMessage(deleteProduct.error)}
        </Alert>
      )}

      {products.length === 0 ? (
        <EmptyState
          title="No products yet"
          description="Add your first listing to start selling."
          action={
            <Link to="/seller/products/new">
              <Button>Add product</Button>
            </Link>
          }
        />
      ) : (
        <>
          <div className="space-y-3">
            {products.map((product) => (
              <Card key={product.id}>
                <CardBody className="flex flex-wrap items-center gap-4">
                  <div className="min-w-48 flex-1">
                    <Link
                      to={`/products/${product.id}`}
                      className="font-medium hover:text-violet-700"
                    >
                      {product.name}
                    </Link>
                    <p className="font-mono text-xs text-muted">{product.sku}</p>
                  </div>

                  {product.is_active ? (
                    <Badge tone="success">Active</Badge>
                  ) : (
                    <Badge tone="neutral">Inactive</Badge>
                  )}

                  <span className="text-sm text-muted">Stock: {product.stock_quantity}</span>
                  <span className="w-28 text-right font-semibold">
                    {formatPrice(product.price)}
                  </span>

                  <div className="flex gap-2">
                    <Link to={`/seller/products/${product.id}`}>
                      <Button variant="secondary" size="sm">
                        Edit
                      </Button>
                    </Link>
                    <Button
                      variant="ghost"
                      size="sm"
                      loading={deleteProduct.isPending}
                      onClick={() => {
                        // A product that has been sold is deactivated rather
                        // than deleted, so this is not always destructive.
                        if (window.confirm(`Remove "${product.name}" from your catalogue?`)) {
                          deleteProduct.mutate(product.id)
                        }
                      }}
                    >
                      Delete
                    </Button>
                  </div>
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
