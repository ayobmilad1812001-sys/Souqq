import type { ReactNode } from 'react'

export function EmptyState({
  title,
  description,
  action,
}: {
  title: string
  description?: string
  action?: ReactNode
}) {
  return (
    <div className="rounded-card bg-white px-6 py-14 text-center">
      <p className="text-base font-bold text-ink">{title}</p>
      {description && <p className="mx-auto mt-1.5 max-w-md text-sm text-muted">{description}</p>}
      {action && <div className="mt-5 flex justify-center">{action}</div>}
    </div>
  )
}
