import { useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'

import type { ProductFilters, ProductSort } from '@/api/types'
import { Alert } from '@/components/ui/Alert'
import { EmptyState } from '@/components/ui/EmptyState'
import { Pagination } from '@/components/ui/Pagination'
import { ProductCardSkeleton } from '@/components/ui/Spinner'
import { errorMessage } from '@/hooks/useAuth'
import { useCategories, useProducts } from '@/hooks/useCatalog'
import { useDebounced } from '@/hooks/useDebounced'
import { formatPrice, toDecimal, toMinor } from '@/lib/money'

import { CategoryPills } from './CategoryPills'
import { FilterSidebar, type FilterValues } from './FilterSidebar'
import { ProductCard } from './ProductCard'

const DEFAULTS: FilterValues = {
  search: '',
  category: '',
  min_price: '',
  max_price: '',
  in_stock: false,
  sort: 'newest',
}

export function CatalogPage() {
  // Filters live in the URL so a filtered catalogue is shareable and the
  // browser back button restores the previous view.
  const [searchParams, setSearchParams] = useSearchParams()

  const [filters, setFilters] = useState<FilterValues>(() => ({
    search: searchParams.get('search') ?? DEFAULTS.search,
    category: searchParams.get('category') ?? DEFAULTS.category,
    min_price: searchParams.get('min_price') ?? DEFAULTS.min_price,
    max_price: searchParams.get('max_price') ?? DEFAULTS.max_price,
    in_stock: searchParams.get('in_stock') === '1',
    sort: (searchParams.get('sort') as ProductSort | null) ?? DEFAULTS.sort,
  }))

  const [page, setPage] = useState(() => Number(searchParams.get('page') ?? 1))

  // The header search box writes straight to the URL, so mirror it back in.
  const urlSearch = searchParams.get('search') ?? ''

  useEffect(() => {
    setFilters((current) =>
      current.search === urlSearch ? current : { ...current, search: urlSearch },
    )
  }, [urlSearch])

  const debouncedSearch = useDebounced(filters.search)

  useEffect(() => {
    const next = new URLSearchParams()

    if (debouncedSearch) next.set('search', debouncedSearch)
    if (filters.category) next.set('category', filters.category)
    if (filters.min_price) next.set('min_price', filters.min_price)
    if (filters.max_price) next.set('max_price', filters.max_price)
    if (filters.in_stock) next.set('in_stock', '1')
    if (filters.sort !== DEFAULTS.sort) next.set('sort', filters.sort)
    if (page > 1) next.set('page', String(page))

    setSearchParams(next, { replace: true })
  }, [debouncedSearch, filters, page, setSearchParams])

  const query = useMemo<ProductFilters>(
    () => ({
      search: debouncedSearch || undefined,
      category: filters.category || undefined,
      min_price: filters.min_price || undefined,
      max_price: filters.max_price || undefined,
      in_stock: filters.in_stock || undefined,
      sort: filters.sort,
      per_page: 12,
      page,
    }),
    [debouncedSearch, filters, page],
  )

  const { data, isPending, error, isPlaceholderData } = useProducts(query)
  const { data: categories } = useCategories()

  const products = data?.data ?? []

  // Averaged in minor units, and labelled as covering the visible results --
  // the API exposes no catalogue-wide price statistic to use instead.
  const averageLabel = useMemo(() => {
    if (products.length === 0) return undefined

    const total = products.reduce((sum, product) => sum + toMinor(product.price), 0)
    const mean = toDecimal(Math.round(total / products.length))

    return `Average price on this page is ${formatPrice(mean)}`
  }, [products])

  function handleChange(patch: Partial<FilterValues>) {
    setFilters((current) => ({ ...current, ...patch }))
    setPage(1) // A changed filter invalidates the current page number.
  }

  function handleReset() {
    setFilters(DEFAULTS)
    setPage(1)
  }

  return (
    <div>
      <CategoryPills
        categories={categories?.data ?? []}
        active={filters.category}
        onSelect={(slug) => handleChange({ category: slug })}
      />

      <div className="flex flex-col gap-5 lg:flex-row">
        <FilterSidebar
          values={filters}
          categories={categories?.data ?? []}
          averageLabel={averageLabel}
          onChange={handleChange}
          onReset={handleReset}
        />

        <div className="min-w-0 flex-1">
          {error && <Alert tone="error">{errorMessage(error)}</Alert>}

          {isPending ? (
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
              {Array.from({ length: 6 }, (_, index) => (
                <ProductCardSkeleton key={index} />
              ))}
            </div>
          ) : products.length === 0 ? (
            <EmptyState
              title="No products match those filters"
              description="Try widening the price range, clearing the search, or picking another category."
            />
          ) : (
            <div className={isPlaceholderData ? 'opacity-60 transition' : 'transition'}>
              <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                {products.map((product) => (
                  <ProductCard key={product.id} product={product} />
                ))}
              </div>

              {data?.meta && (
                <div className="mt-6 rounded-card bg-white px-4 py-3">
                  <Pagination meta={data.meta} onPageChange={setPage} />
                </div>
              )}
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
