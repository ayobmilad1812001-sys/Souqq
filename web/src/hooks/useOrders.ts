import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import type { ApiResult } from '@/api/client'
import { ordersApi, type OrderFilters } from '@/api/endpoints/orders'
import type { Order, OrderStatus } from '@/api/types'
import { queryKeys } from '@/lib/queryKeys'

export function useOrders(filters: OrderFilters = {}) {
  return useQuery({
    queryKey: queryKeys.orders(filters),
    queryFn: () => ordersApi.list(filters),
    placeholderData: (previous) => previous,
  })
}

export function useOrder(id: number) {
  return useQuery({
    queryKey: queryKeys.order(id),
    queryFn: () => ordersApi.get(id),
    enabled: Number.isFinite(id) && id > 0,
  })
}

export function usePlaceOrder() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: () => ordersApi.create(),
    onSuccess: () => {
      // The cart is now empty and stock has moved, so the cart and the whole
      // catalogue are stale. The order lists gain a row.
      void queryClient.invalidateQueries({ queryKey: queryKeys.cart })
      void queryClient.invalidateQueries({ queryKey: ['orders'] })
      void queryClient.invalidateQueries({ queryKey: ['products'] })
    },
  })
}

/** Shared invalidation for both routes that move an order to a new status. */
function useOrderTransition<TInput>(mutationFn: (input: TInput) => Promise<ApiResult<Order>>) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn,
    onSuccess: ({ data }) => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.order(data.id) })
      void queryClient.invalidateQueries({ queryKey: ['orders'] })
      // Cancelling returns stock to the catalogue.
      void queryClient.invalidateQueries({ queryKey: ['products'] })
      void queryClient.invalidateQueries({ queryKey: queryKeys.stats })
    },
  })
}

export function useUpdateOrderStatus() {
  return useOrderTransition((input: { id: number; status: OrderStatus }) =>
    ordersApi.updateStatus(input.id, input.status),
  )
}

export function useCancelOrder() {
  return useOrderTransition((id: number) => ordersApi.cancel(id))
}
