import { Outlet } from 'react-router-dom'

import { BRAND } from '@/lib/brand'

import { Header } from './Header'

export function AppLayout() {
  return (
    // The violet field is painted on <body>; the app itself is one rounded
    // surface floating on it, as in the reference design.
    <div className="mx-auto flex min-h-screen max-w-[1400px] flex-col p-3 sm:p-6">
      <div className="flex flex-1 flex-col overflow-hidden rounded-3xl bg-white shadow-[0_24px_80px_-20px_rgb(47_26_112_/_0.45)]">
        <Header />

        <main className="flex-1 bg-surface px-4 py-5 sm:px-6 sm:py-6">
          <Outlet />
        </main>
      </div>

      <p className="px-2 py-4 text-center text-xs text-white/70">
        {BRAND.name} &middot; {BRAND.tagline}
      </p>
    </div>
  )
}
