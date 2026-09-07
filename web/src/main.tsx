import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'

import { ApiError, setUnauthorizedHandler } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

import App from './App.tsx'
import './index.css'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30 * 1000,
      retry: (failureCount, error) => {
        // Retrying a 4xx just repeats the same rejection. Rate limits in
        // particular get worse, not better, when hammered.
        if (error instanceof ApiError && error.status >= 400 && error.status < 500) {
          return false
        }

        return failureCount < 2
      },
    },
    mutations: {
      // A failed write must never be replayed automatically: a retried
      // checkout could place a second order.
      retry: false,
    },
  },
})

// A 401 from anywhere drops the session, so the guards send the user to /login.
setUnauthorizedHandler(() => {
  useAuthStore.getState().signOut()
  queryClient.clear()
})

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>
        <App />
      </BrowserRouter>
    </QueryClientProvider>
  </StrictMode>,
)
