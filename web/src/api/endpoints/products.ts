import { api } from '../client'
import type { Product, ProductFilters } from '../types'

export interface ProductInput {
  name: string
  description: string
  /** A decimal string with at most 2 places -- the API enforces decimal:0,2. */
  price: string
  sku: string
  stock_quantity: number
  category_id: number
  is_active?: boolean
}

export const productsApi = {
  list: (filters: ProductFilters = {}) =>
    api<Product[]>('/products', { query: filters as Record<string, unknown> }),

  get: (id: number) => api<Product>(`/products/${id}`),

  // There is no seller_id field: ownership comes from the token. Sending one
  // is ignored by the API.
  create: (input: ProductInput) => api<Product>('/products', { method: 'POST', body: input }),

  update: (id: number, input: Partial<ProductInput>) =>
    api<Product>(`/products/${id}`, { method: 'PATCH', body: input }),

  /** A product that has been sold is deactivated rather than hard deleted. */
  remove: (id: number) => api<null>(`/products/${id}`, { method: 'DELETE' }),
}
