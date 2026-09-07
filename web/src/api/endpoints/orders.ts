import { api } from '../client'
import type { Order, OrderStatus } from '../types'

export interface OrderFilters {
  status?: OrderStatus
  per_page?: number
  page?: number
}

export const ordersApi = {
  /** Role-scoped by the API: customers see their own, sellers see orders
   *  containing their products, admins see everything. No client filtering. */
  list: (filters: OrderFilters = {}) =>
    api<Order[]>('/orders', { query: filters as Record<string, unknown> }),

  get: (id: number) => api<Order>(`/orders/${id}`),

  /** No body -- checkout reads the authenticated user's cart. */
  create: () => api<Order>('/orders', { method: 'POST' }),

  /** Seller/admin fulfilment. Sellers may advance but never cancel. */
  updateStatus: (id: number, status: OrderStatus) =>
    api<Order>(`/orders/${id}/status`, { method: 'PATCH', body: { status } }),

  /** Customer self-service. Pending/confirmed only, within the 60-min window. */
  cancel: (id: number) => api<Order>(`/orders/${id}/cancel`, { method: 'PATCH' }),
}
