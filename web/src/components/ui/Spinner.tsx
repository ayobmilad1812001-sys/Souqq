import { cn } from '@/lib/cn'

export function Spinner({ className }: { className?: string }) {
  return (
    <span
      role="status"
      aria-label="Loading"
      className={cn(
        'inline-block size-5 animate-spin rounded-full border-2 border-violet-100 border-t-violet-600',
        className,
      )}
    />
  )
}

export function PageSpinner() {
  return (
    <div className="flex min-h-64 items-center justify-center">
      <Spinner className="size-8" />
    </div>
  )
}

/** Skeletons, not spinners, wherever the shape of the content is known. */
export function Skeleton({ className }: { className?: string }) {
  return <div className={cn('animate-pulse rounded-xl bg-line', className)} />
}

export function ProductCardSkeleton() {
  return (
    <div className="rounded-card bg-white p-3">
      <Skeleton className="mb-3 aspect-4/3 w-full" />
      <Skeleton className="mx-auto mb-3 h-4 w-3/4" />
      <Skeleton className="mx-auto h-8 w-28 rounded-pill" />
    </div>
  )
}
