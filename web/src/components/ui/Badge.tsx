import type { ReactNode } from 'react'

import type { OrderStatus } from '@/api/types'
import { cn } from '@/lib/cn'

type Tone = 'neutral' | 'success' | 'warning' | 'danger' | 'info' | 'gold'

const TONES: Record<Tone, string> = {
  neutral: 'bg-surface text-ink-soft',
  success: 'bg-emerald-50 text-emerald-700',
  warning: 'bg-amber-50 text-amber-700',
  danger: 'bg-rose-50 text-rose-600',
  info: 'bg-violet-50 text-violet-700',
  gold: 'bg-gold-400 text-ink',
}

export function Badge({
  tone = 'neutral',
  className,
  children,
}: {
  tone?: Tone
  className?: string
  children: ReactNode
}) {
  return (
    <span
      className={cn(
        'inline-flex items-center rounded-pill px-2.5 py-1 text-[11px] font-bold capitalize',
        TONES[tone],
        className,
      )}
    >
      {children}
    </span>
  )
}

const STATUS_TONES: Record<OrderStatus, Tone> = {
  pending: 'warning',
  confirmed: 'info',
  processing: 'info',
  shipped: 'info',
  delivered: 'success',
  cancelled: 'danger',
}

export function OrderStatusBadge({ status }: { status: OrderStatus }) {
  return <Badge tone={STATUS_TONES[status]}>{status}</Badge>
}
