import { Link } from 'react-router-dom'

import { BRAND } from '@/lib/brand'

/** Two-tone wordmark: dark stem, violet tail. */
export function Logo({ className = '' }: { className?: string }) {
  return (
    <Link
      to="/"
      aria-label={BRAND.name}
      className={`text-2xl font-extrabold tracking-tight ${className}`}
    >
      <span className="text-ink">{BRAND.namePrefix}</span>
      <span className="text-violet-600">{BRAND.nameSuffix}</span>
    </Link>
  )
}
