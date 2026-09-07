import type { PaginationMeta } from '@/api/types'

import { Button } from './Button'

export function Pagination({
  meta,
  onPageChange,
}: {
  meta: PaginationMeta
  onPageChange: (page: number) => void
}) {
  if (meta.last_page <= 1) {
    return null
  }

  const from = (meta.current_page - 1) * meta.per_page + 1
  const to = Math.min(meta.current_page * meta.per_page, meta.total)

  return (
    <nav
      aria-label="Pagination"
      className="flex flex-wrap items-center justify-between gap-3 border-t border-line pt-4"
    >
      <p className="text-sm text-muted">
        Showing <span className="font-medium">{from}</span>–<span className="font-medium">{to}</span>{' '}
        of <span className="font-medium">{meta.total}</span>
      </p>

      <div className="flex items-center gap-2">
        <Button
          variant="secondary"
          size="sm"
          disabled={meta.current_page <= 1}
          onClick={() => onPageChange(meta.current_page - 1)}
        >
          Previous
        </Button>
        <span className="text-sm text-muted">
          Page {meta.current_page} of {meta.last_page}
        </span>
        <Button
          variant="secondary"
          size="sm"
          disabled={meta.current_page >= meta.last_page}
          onClick={() => onPageChange(meta.current_page + 1)}
        >
          Next
        </Button>
      </div>
    </nav>
  )
}
