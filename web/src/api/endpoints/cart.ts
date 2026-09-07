import { api } from '../client'
import type { Cart } from '../types'

/**
 * Every one of these returns the COMPLETE cart with recomputed totals, so the
 * caller should write the response straight into the cache rather than
 * refetching. One round trip per interaction, not two.
 */
export const cartApi = {
  get: () => api<Cart>('/cart'),

  addItem: (input: { product_id: number; quantity: number }) =>
    api<Cart>('/cart/items', { method: 'POST', body: input }),

  /** An absolute quantity, not a delta -- so a retry is idempotent. */
  updateItem: (itemId: number, quantity: number) =>
    api<Cart>(`/cart/items/${itemId}`, { method: 'PATCH', body: { quantity } }),

  /** Removing a line is DELETE. Setting quantity to 0 is a 422. */
  removeItem: (itemId: number) => api<Cart>(`/cart/items/${itemId}`, { method: 'DELETE' }),
}
