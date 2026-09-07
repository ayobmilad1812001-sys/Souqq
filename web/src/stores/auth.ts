import { create } from 'zustand'

import { clearToken, getToken, setToken } from '@/api/client'
import type { User } from '@/api/types'

interface AuthState {
  user: User | null
  token: string | null
  /** True until the boot-time `GET /user` check has settled. */
  isBootstrapping: boolean

  signIn: (user: User, token: string) => void
  signOut: () => void
  setUser: (user: User | null) => void
  finishBootstrap: () => void
}

export const useAuthStore = create<AuthState>((set) => ({
  // Read the token synchronously so a refresh does not flash the signed-out UI.
  user: null,
  token: getToken(),
  isBootstrapping: getToken() !== null,

  signIn: (user, token) => {
    setToken(token)
    set({ user, token, isBootstrapping: false })
  },

  signOut: () => {
    clearToken()
    set({ user: null, token: null, isBootstrapping: false })
  },

  setUser: (user) => set({ user }),

  finishBootstrap: () => set({ isBootstrapping: false }),
}))

/* Selectors -- subscribing to one field avoids re-rendering on unrelated changes. */
export const useUser = () => useAuthStore((state) => state.user)
export const useIsAuthenticated = () => useAuthStore((state) => state.token !== null)
export const useRole = () => useAuthStore((state) => state.user?.role ?? null)
