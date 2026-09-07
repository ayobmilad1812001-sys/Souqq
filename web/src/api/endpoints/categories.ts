import { api } from '../client'
import type { Category } from '../types'

export const categoriesApi = {
  list: () => api<Category[]>('/categories'),

  get: (id: number) => api<Category>(`/categories/${id}`),

  create: (input: { name: string }) =>
    api<Category>('/categories', { method: 'POST', body: input }),

  /** Renaming deliberately keeps the existing slug, so links do not break. */
  update: (id: number, input: { name?: string; slug?: string }) =>
    api<Category>(`/categories/${id}`, { method: 'PATCH', body: input }),

  /** 409 if the category still contains products. */
  remove: (id: number) => api<null>(`/categories/${id}`, { method: 'DELETE' }),
}
