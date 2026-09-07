import { api } from '../client'
import type { Stats } from '../types'

export const statsApi = {
  /** Returns a seller-scoped or platform-scoped shape; branch on `scope`. */
  get: () => api<Stats>('/stats'),
}
