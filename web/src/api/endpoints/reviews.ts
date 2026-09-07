import { api } from '../client'
import type { Review } from '../types'

export const reviewsApi = {
  list: (productId: number) => api<Review[]>(`/products/${productId}/reviews`),

  /** Verified purchasers only; a seller cannot review their own product. */
  create: (productId: number, input: { rating: number; comment?: string }) =>
    api<Review>(`/products/${productId}/reviews`, { method: 'POST', body: input }),

  remove: (reviewId: number) => api<null>(`/reviews/${reviewId}`, { method: 'DELETE' }),
}
