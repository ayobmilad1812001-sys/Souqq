import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useEffect } from 'react'
import { useNavigate } from 'react-router-dom'

import { ApiError } from '@/api/client'
import { authApi, type LoginInput, type RegisterInput } from '@/api/endpoints/auth'
import { useAuthStore } from '@/stores/auth'

/**
 * On boot, validate a stored token by fetching the profile. A 401 is handled
 * inside the client (token cleared, handler fired), so all we settle here is
 * the bootstrapping flag.
 */
export function useBootstrapAuth() {
  const token = useAuthStore((state) => state.token)
  const user = useAuthStore((state) => state.user)
  const setUser = useAuthStore((state) => state.setUser)
  const finishBootstrap = useAuthStore((state) => state.finishBootstrap)

  useEffect(() => {
    if (!token || user) {
      finishBootstrap()
      return
    }

    let cancelled = false

    authApi
      .me()
      .then(({ data }) => {
        if (!cancelled) setUser(data)
      })
      .catch(() => {
        /* A 401 already cleared the token via the client handler. */
      })
      .finally(() => {
        if (!cancelled) finishBootstrap()
      })

    return () => {
      cancelled = true
    }
  }, [token, user, setUser, finishBootstrap])
}

export function useLogin() {
  const signIn = useAuthStore((state) => state.signIn)
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: LoginInput) => authApi.login(input),
    onSuccess: ({ data }) => {
      signIn(data.user, data.token)
      // A previous visitor cached cart and orders; none of it belongs to this session.
      queryClient.clear()
    },
  })
}

export function useRegister() {
  const signIn = useAuthStore((state) => state.signIn)
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: RegisterInput) => authApi.register(input),
    onSuccess: ({ data }) => {
      signIn(data.user, data.token)
      queryClient.clear()
    },
  })
}

export function useLogout() {
  const signOut = useAuthStore((state) => state.signOut)
  const queryClient = useQueryClient()
  const navigate = useNavigate()

  return useMutation({
    mutationFn: () => authApi.logout(),
    // Sign out locally either way: if the token was already dead server-side,
    // the user still expects the UI to log them out.
    onSettled: () => {
      signOut()
      queryClient.clear()
      navigate('/')
    },
  })
}

/** Turns any thrown value into a message that is safe to show a user. */
export function errorMessage(error: unknown): string {
  if (error instanceof ApiError) {
    return error.message
  }

  return 'Something went wrong. Please try again.'
}
