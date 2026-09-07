import { api } from '../client'
import type { AuthPayload, Role, User } from '../types'

export interface RegisterInput {
  name: string
  email: string
  password: string
  password_confirmation: string
  /** The API rejects 'admin' here -- self-registration cannot escalate. */
  role?: Extract<Role, 'customer' | 'seller'>
}

export interface LoginInput {
  email: string
  password: string
  device_name?: string
}

export const authApi = {
  register: (input: RegisterInput) =>
    api<AuthPayload>('/register', { method: 'POST', body: input }),

  login: (input: LoginInput) =>
    api<AuthPayload>('/login', { method: 'POST', body: input }),

  /** Revokes only the current token, so other devices stay signed in. */
  logout: () => api<null>('/logout', { method: 'POST' }),

  me: () => api<User>('/user'),
}
