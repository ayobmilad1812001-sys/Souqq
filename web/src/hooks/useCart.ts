import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { cartApi } from '@/api/endpoints/cart'
import type { ApiResult } from '@/api/client'
import type { Cart } from '@/api/types'
import { queryKeys } from '@/lib/queryKeys'
import { useIsAuthenticated } from '@/stores/auth'

export function useCart() {
  const isAuthenticated = useIsAuthenticated()

  return useQuery({
    queryKey: queryKeys.cart,
    queryFn: () => cartApi.get(),
    enabled: isAuthenticated,
  })
}

/**
 * Every cart endpoint returns the COMPLETE cart with recomputed totals, so the
 * response goes straight into the cache. Refetching afterwards would be a
 * second round trip for data we are already holding.
 */
function useCartMutation<TInput>(mutationFn: (input: TInput) => Promise<ApiResult<Cart>>) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn,
    onSuccess: (result) => queryClient.setQueryData(queryKeys.cart, result),
  })
}

export function useAddToCart() {
  return useCartMutation((input: { product_id: number; quantity: number }) =>
    cartApi.addItem(input),
  )
}

export function useUpdateCartItem() {
  return useCartMutation((input: { itemId: number; quantity: number }) =>
    cartApi.updateItem(input.itemId, input.quantity),
  )
}

export function useRemoveCartItem() {
  return useCartMutation((itemId: number) => cartApi.removeItem(itemId))
}
