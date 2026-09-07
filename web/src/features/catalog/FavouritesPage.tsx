import { useQueries } from '@tanstack/react-query'
import { Link } from 'react-router-dom'

import { productsApi } from '@/api/endpoints/products'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { EmptyState } from '@/components/ui/EmptyState'
import { ProductCardSkeleton } from '@/components/ui/Spinner'
import { queryKeys } from '@/lib/queryKeys'
import { useFavouritesStore } from '@/stores/favourites'

import { ProductCard } from './ProductCard'

export function FavouritesPage() {
  const ids = useFavouritesStore((state) => state.ids)

  // There is no favourites resource and no batch endpoint on the API, so each
  // saved product is fetched by id. useQueries keeps them individually cached
  // and shares those entries with the product detail page.
  const results = useQueries({
    queries: ids.map((id) => ({
      queryKey: queryKeys.product(id),
      queryFn: () => productsApi.get(id),
    })),
  })

  if (ids.length === 0) {
    return (
      <EmptyState
        title="No favourites yet"
        description="Tap the heart on any product to keep it here."
        action={
          <Link to="/">
            <Button>Browse products</Button>
          </Link>
        }
      />
    )
  }

  const isPending = results.some((result) => result.isPending)
  const products = results.flatMap((result) => (result.data ? [result.data.data] : []))

  return (
    <div>
      <h1 className="mb-1 text-2xl font-extrabold">Favourites</h1>
      <p className="mb-5 text-sm text-muted">
        Saved in this browser only &mdash; they will not follow you to another device.
      </p>

      {results.some((result) => result.error) && (
        <Alert tone="warning" className="mb-4">
          Some saved products could not be loaded. They may have been removed.
        </Alert>
      )}

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        {isPending
          ? ids.map((id) => <ProductCardSkeleton key={id} />)
          : products.map((product) => <ProductCard key={product.id} product={product} />)}
      </div>
    </div>
  )
}
