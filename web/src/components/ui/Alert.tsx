import type { ReactNode } from 'react'

import { cn } from '@/lib/cn'

type Tone = 'error' | 'success' | 'info' | 'warning'

const TONES: Record<Tone, string> = {
  error: 'bg-rose-50 text-rose-700',
  success: 'bg-emerald-50 text-emerald-700',
  info: 'bg-violet-50 text-violet-800',
  warning: 'bg-amber-50 text-amber-800',
}

export function Alert({
  tone = 'info',
  title,
  className,
  children,
}: {
  tone?: Tone
  title?: string
  className?: string
  children?: ReactNode
}) {
  return (
    <div
      // Errors are announced immediately; the rest wait for a natural pause.
      role={tone === 'error' ? 'alert' : 'status'}
      className={cn('rounded-2xl px-4 py-3 text-sm', TONES[tone], className)}
    >
      {title && <p className="font-bold">{title}</p>}
      {children}
    </div>
  )
}
