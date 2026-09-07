import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'

import { ApiError } from '@/api/client'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card, CardBody } from '@/components/ui/Card'
import { EmptyState } from '@/components/ui/EmptyState'
import { HeartIcon, StarIcon } from '@/components/ui/Icons'
import { PageSpinner } from '@/components/ui/Spinner'
import { errorMessage } from '@/hooks/useAuth'
import { useAddToCart } from '@/hooks/useCart'
import { useProduct } from '@/hooks/useCatalog'
import { cn } from '@/lib/cn'
import { formatPrice } from '@/lib/money'
import { productVisual } from '@/lib/productVisual'
import { useIsAuthenticated } from '@/stores/auth'
import { useFavouritesStore } from '@/stores/favourites'

import { ReviewSection } from './ReviewSection'

export function ProductDetailPage() {
  const { id } = useParams<{ id: string }>()
  const productId = Number(id)

  const { data, isPending, error } = useProduct(productId)
  const addToCart = useAddToCart()
  const isAuthenticated = useIsAuthenticated()
  const favourites = useFavouritesStore()

  const [quantity, setQuantity] = useState(1)

  if (isPending) return <PageSpinner />

  if (error) {
    return (
      <EmptyState
        title={
          error instanceof ApiError && error.isNotFound
            ? 'Product not found'
            : 'Could not load this product'
        }
        description={errorMessage(error)}
        action={
          <Link to="/">
            <Button variant="secondary">Back to catalogue</Button>
          </Link>
        }
      />
    )
  }

  const product = data.data
  const visual = productVisual(product.id, product.name)
  const isFavourite = favourites.ids.includes(product.id)

  // A 409 here carries the authoritative remaining stock, read while the API
  // held the row lock -- worth surfacing rather than a generic message.
  const conflict = addToCart.error instanceof ApiError ? addToCart.error.stockConflict() : null

  return (
    <div>
      <Link to="/" className="text-sm font-semibold text-violet-700 hover:underline">
        &larr; Back to catalogue
      </Link>

      <div className="mt-4 grid gap-5 lg:grid-cols-5">
        <div className="lg:col-span-3">
          <div
            className="relative grid aspect-16/10 place-items-center rounded-card"
            style={{ background: visual.background }}
          >
            <span className="text-7xl font-extrabold text-white/70 select-none">
              {visual.initials}
            </span>

            <button
              type="button"
              aria-label={isFavourite ? 'Remove from favourites' : 'Add to favourites'}
              aria-pressed={isFavourite}
              onClick={() => favourites.toggle(product.id)}
              className={cn(
                'absolute top-4 right-4 grid size-11 place-items-center rounded-full transition',
                isFavourite ? 'bg-violet-600 text-white' : 'bg-white/90 text-ink-soft',
              )}
            >
              <HeartIcon filled={isFavourite} className="size-5" />
            </button>
          </div>

          <div className="mt-5 rounded-card bg-white p-5">
            <h1 className="text-2xl font-extrabold">{product.name}</h1>

            <div className="mt-2 flex flex-wrap items-center gap-2 text-sm text-muted">
              {product.category && <Badge tone="info">{product.category.name}</Badge>}
              {product.seller && <span>Sold by {product.seller.name}</span>}
              {/* average_rating exists only on the detail endpoint, so it is probed. */}
              {product.average_rating && (
                <span className="inline-flex items-center gap-1 font-semibold text-gold-600">
                  <StarIcon filled className="size-4" />
                  {product.average_rating}
                  {product.reviews_count !== undefined && ` (${product.reviews_count})`}
                </span>
              )}
            </div>

            <p className="mt-4 whitespace-pre-line text-ink-soft">{product.description}</p>

            <dl className="mt-6 grid grid-cols-2 gap-4 text-sm sm:grid-cols-3">
              <div>
                <dt className="text-muted">SKU</dt>
                <dd className="font-mono font-semibold">{product.sku}</dd>
              </div>
              <div>
                <dt className="text-muted">Stock</dt>
                <dd className="font-semibold">{product.stock_quantity}</dd>
              </div>
            </dl>
          </div>

          <ReviewSection productId={productId} />
        </div>

        <div className="lg:col-span-2">
          <Card className="lg:sticky lg:top-6">
            <CardBody className="space-y-4">
              <p className="text-3xl font-extrabold">{formatPrice(product.price)}</p>

              {product.in_stock ? (
                <Badge tone="success">In stock &middot; {product.stock_quantity} available</Badge>
              ) : (
                <Badge tone="danger">Sold out</Badge>
              )}

              {conflict && (
                <Alert tone="warning" title="Not enough stock">
                  You asked for {conflict.requested} but only {conflict.available} remain.
                  {conflict.available > 0 && (
                    <Button
                      size="sm"
                      variant="secondary"
                      className="mt-2"
                      onClick={() => {
                        setQuantity(conflict.available)
                        addToCart.mutate({
                          product_id: product.id,
                          quantity: conflict.available,
                        })
                      }}
                    >
                      Add {conflict.available} instead
                    </Button>
                  )}
                </Alert>
              )}

              {addToCart.error && !conflict && (
                <Alert tone="error">{errorMessage(addToCart.error)}</Alert>
              )}

              {addToCart.isSuccess && !addToCart.error && (
                <Alert tone="success">
                  Added to your cart.{' '}
                  <Link to="/cart" className="font-bold underline">
                    View cart
                  </Link>
                </Alert>
              )}

              {isAuthenticated ? (
                <>
                  <label className="block text-sm font-semibold">
                    Quantity
                    <input
                      type="number"
                      min={1}
                      max={Math.min(product.stock_quantity, 100)}
                      value={quantity}
                      onChange={(event) => setQuantity(Math.max(1, Number(event.target.value)))}
                      className="mt-1.5 w-full rounded-2xl border border-line px-4 py-2.5 text-sm"
                    />
                  </label>

                  <Button
                    className="w-full"
                    size="lg"
                    disabled={!product.in_stock}
                    loading={addToCart.isPending}
                    onClick={() => addToCart.mutate({ product_id: product.id, quantity })}
                  >
                    Add to cart
                  </Button>
                </>
              ) : (
                <Link to="/login" className="block">
                  <Button className="w-full" size="lg">
                    Sign in to buy
                  </Button>
                </Link>
              )}
            </CardBody>
          </Card>
        </div>
      </div>
    </div>
  )
}
