import type { ProductFilters } from '@/api/types'

/**
 * Every cache key in one place, so an invalidation after a mutation cannot
 * silently miss the query it was meant to refresh.
 *
 * Filters are part of the product listing key, so each filter combination
 * caches separately -- mirroring how the API caches listings per filter set.
 */
export const queryKeys = {
  currentUser: ['user'] as const,

  categories: ['categories'] as const,
  category: (id: number) => ['categories', id] as const,

  products: (filters: ProductFilters = {}) => ['products', filters] as const,
  product: (id: number) => ['products', id] as const,
  reviews: (productId: number) => ['products', productId, 'reviews'] as const,

  cart: ['cart'] as const,

  orders: (filters: object = {}) => ['orders', filters] as const,
  order: (id: number) => ['orders', id] as const,

  stats: ['stats'] as const,
} as const
