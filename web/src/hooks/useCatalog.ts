import { useMutation, useQuery, useQueryClient, type QueryClient } from '@tanstack/react-query'

import { categoriesApi } from '@/api/endpoints/categories'
import { productsApi, type ProductInput } from '@/api/endpoints/products'
import { reviewsApi } from '@/api/endpoints/reviews'
import type { ProductFilters } from '@/api/types'
import { queryKeys } from '@/lib/queryKeys'

export function useProducts(filters: ProductFilters) {
  return useQuery({
    queryKey: queryKeys.products(filters),
    queryFn: () => productsApi.list(filters),
    // Keep the previous page on screen while the next loads, so the grid does
    // not collapse into a spinner on every keystroke or page change.
    placeholderData: (previous) => previous,
  })
}

export function useProduct(id: number) {
  return useQuery({
    queryKey: queryKeys.product(id),
    queryFn: () => productsApi.get(id),
    enabled: Number.isFinite(id) && id > 0,
  })
}

export function useCategories() {
  return useQuery({
    queryKey: queryKeys.categories,
    queryFn: () => categoriesApi.list(),
    // The taxonomy changes rarely. No need to refetch it constantly.
    staleTime: 5 * 60 * 1000,
  })
}

export function useProductReviews(productId: number) {
  return useQuery({
    queryKey: queryKeys.reviews(productId),
    queryFn: () => reviewsApi.list(productId),
    enabled: Number.isFinite(productId) && productId > 0,
  })
}

/** Any product write invalidates every listing, plus that product's detail. */
function invalidateCatalog(queryClient: QueryClient, id?: number) {
  void queryClient.invalidateQueries({ queryKey: ['products'] })
  void queryClient.invalidateQueries({ queryKey: queryKeys.stats })

  if (id) {
    void queryClient.invalidateQueries({ queryKey: queryKeys.product(id) })
  }
}

export function useCreateProduct() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: ProductInput) => productsApi.create(input),
    onSuccess: () => invalidateCatalog(queryClient),
  })
}

export function useUpdateProduct(id: number) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: Partial<ProductInput>) => productsApi.update(id, input),
    onSuccess: () => invalidateCatalog(queryClient, id),
  })
}

export function useDeleteProduct() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: number) => productsApi.remove(id),
    onSuccess: (_result, id) => invalidateCatalog(queryClient, id),
  })
}

export function useCreateReview(productId: number) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: { rating: number; comment?: string }) =>
      reviewsApi.create(productId, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.reviews(productId) })
      // A new review moves the average rating and the review count.
      void queryClient.invalidateQueries({ queryKey: queryKeys.product(productId) })
    },
  })
}

export function useDeleteReview(productId: number) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (reviewId: number) => reviewsApi.remove(reviewId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.reviews(productId) })
      void queryClient.invalidateQueries({ queryKey: queryKeys.product(productId) })
    },
  })
}
