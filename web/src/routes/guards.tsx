import type { ReactNode } from 'react'
import { Navigate, useLocation } from 'react-router-dom'

import type { Role } from '@/api/types'
import { PageSpinner } from '@/components/ui/Spinner'
import { useAuthStore } from '@/stores/auth'

/** Requires a session. Remembers where the visitor was headed. */
export function ProtectedRoute({ children }: { children: ReactNode }) {
  const token = useAuthStore((state) => state.token)
  const isBootstrapping = useAuthStore((state) => state.isBootstrapping)
  const location = useLocation()

  // Without this, a refresh on a protected page bounces to /login before the
  // stored token has been validated.
  if (isBootstrapping) {
    return <PageSpinner />
  }

  if (!token) {
    return <Navigate to="/login" replace state={{ from: location.pathname }} />
  }

  return <>{children}</>
}

/** Requires one of the given roles. Mirrors the API's `role:` middleware. */
export function RoleRoute({ roles, children }: { roles: Role[]; children: ReactNode }) {
  const user = useAuthStore((state) => state.user)
  const token = useAuthStore((state) => state.token)
  const isBootstrapping = useAuthStore((state) => state.isBootstrapping)

  if (isBootstrapping || (token && !user)) {
    return <PageSpinner />
  }

  if (!token) {
    return <Navigate to="/login" replace />
  }

  if (!user || !roles.includes(user.role)) {
    return <Navigate to="/forbidden" replace />
  }

  return <>{children}</>
}
