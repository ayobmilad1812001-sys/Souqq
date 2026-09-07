import type { Category } from '@/api/types'
import { cn } from '@/lib/cn'

/** The horizontal category strip from the reference, scrollable on mobile. */
export function CategoryPills({
  categories,
  active,
  onSelect,
}: {
  categories: Category[]
  active: string
  onSelect: (slug: string) => void
}) {
  const pills = [{ id: 0, name: 'All Categories', slug: '' }, ...categories]

  return (
    <div className="-mx-1 mb-5 flex gap-2 overflow-x-auto px-1 pb-1">
      {pills.map((category) => {
        const isActive = active === category.slug

        return (
          <button
            key={category.slug || 'all'}
            type="button"
            aria-pressed={isActive}
            onClick={() => onSelect(category.slug)}
            className={cn(
              'shrink-0 rounded-pill px-4 py-2 text-sm font-semibold whitespace-nowrap transition',
              isActive
                ? 'bg-violet-600 text-white'
                : 'bg-white text-ink-soft hover:text-violet-700',
            )}
          >
            {category.name}
          </button>
        )
      })}
    </div>
  )
}
