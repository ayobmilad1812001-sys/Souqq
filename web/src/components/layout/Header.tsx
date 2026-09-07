import { Link, NavLink, useNavigate, useSearchParams } from 'react-router-dom'

import { BoxIcon, CartIcon, HeartIcon, SearchIcon } from '@/components/ui/Icons'
import { useCart } from '@/hooks/useCart'
import { useLogout } from '@/hooks/useAuth'
import { cn } from '@/lib/cn'
import { useFavouriteCount } from '@/stores/favourites'
import { useIsAuthenticated, useUser } from '@/stores/auth'

import { Logo } from './Logo'

function Count({ value, tone }: { value: number; tone: 'violet' | 'rose' }) {
  if (value <= 0) return null

  return (
    <span
      className={cn(
        'absolute -top-1.5 -right-2 min-w-4.5 rounded-full px-1 py-px text-[10px] font-bold text-white',
        tone === 'violet' ? 'bg-violet-600' : 'bg-rose-500',
      )}
    >
      {value > 99 ? '99+' : value}
    </span>
  )
}

function IconLink({
  to,
  label,
  count,
  tone = 'violet',
  children,
}: {
  to: string
  label: string
  count?: number
  tone?: 'violet' | 'rose'
  children: React.ReactNode
}) {
  return (
    <NavLink
      to={to}
      className={({ isActive }) =>
        cn(
          'flex items-center gap-2 rounded-pill px-2.5 py-2 text-sm font-medium transition',
          isActive ? 'text-violet-700' : 'text-ink-soft hover:text-violet-700',
        )
      }
    >
      <span className="relative">
        {children}
        <Count value={count ?? 0} tone={tone} />
      </span>
      <span className="hidden lg:inline">{label}</span>
    </NavLink>
  )
}

export function Header() {
  const user = useUser()
  const isAuthenticated = useIsAuthenticated()
  const logout = useLogout()
  const { data: cart } = useCart()
  const favourites = useFavouriteCount()
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()

  const itemCount = cart?.data.total_quantity ?? 0

  return (
    <header className="flex flex-wrap items-center gap-3 border-b border-line px-4 py-4 sm:px-6">
      <Logo />

      <form
        role="search"
        className="order-3 w-full sm:order-none sm:w-auto sm:flex-1 sm:max-w-md"
        onSubmit={(event) => {
          event.preventDefault()
          const term = new FormData(event.currentTarget).get('search')
          navigate(term ? `/?search=${encodeURIComponent(String(term))}` : '/')
        }}
      >
        <div className="relative">
          <SearchIcon className="pointer-events-none absolute top-1/2 left-4 size-4.5 -translate-y-1/2 text-muted" />
          <input
            name="search"
            type="search"
            defaultValue={searchParams.get('search') ?? ''}
            placeholder="Search"
            aria-label="Search products"
            className="w-full rounded-pill bg-surface py-2.5 pr-4 pl-11 text-sm placeholder:text-muted focus:bg-white focus:ring-2 focus:ring-violet-200"
          />
        </div>
      </form>

      <nav className="ml-auto flex items-center gap-1">
        {isAuthenticated && (
          <IconLink to="/orders" label="Orders">
            <BoxIcon className="size-5.5" />
          </IconLink>
        )}

        <IconLink to="/favourites" label="Favourites" count={favourites}>
          <HeartIcon className="size-5.5" />
        </IconLink>

        {isAuthenticated && (
          <IconLink to="/cart" label="Cart" count={itemCount} tone="rose">
            <CartIcon className="size-5.5" />
          </IconLink>
        )}

        {isAuthenticated ? (
          <div className="flex items-center gap-2 pl-1">
            <span
              title={user?.name}
              className="grid size-9 place-items-center rounded-full bg-violet-600 text-sm font-bold text-white"
            >
              {user?.name?.[0]?.toUpperCase() ?? '?'}
            </span>
            <button
              type="button"
              onClick={() => logout.mutate()}
              className="hidden text-sm font-medium text-muted transition hover:text-ink sm:inline"
            >
              Sign out
            </button>
          </div>
        ) : (
          <div className="flex items-center gap-2 pl-1">
            <Link
              to="/login"
              className="rounded-pill px-3 py-2 text-sm font-semibold text-ink-soft transition hover:text-violet-700"
            >
              Sign in
            </Link>
            <Link
              to="/register"
              className="rounded-pill bg-violet-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-violet-700"
            >
              Create account
            </Link>
          </div>
        )}
      </nav>
    </header>
  )
}
