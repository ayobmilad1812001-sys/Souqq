import { useState } from 'react'

import { PRODUCT_SORTS, SORT_LABELS, type Category, type ProductSort } from '@/api/types'
import { cn } from '@/lib/cn'

import { PriceRange } from './PriceRange'

export interface FilterValues {
  search: string
  category: string
  min_price: string
  max_price: string
  in_stock: boolean
  sort: ProductSort
}

const VISIBLE_CATEGORIES = 6

function CheckRow({
  label,
  count,
  checked,
  onToggle,
}: {
  label: string
  count?: number
  checked: boolean
  onToggle: () => void
}) {
  return (
    <li>
      <label className="flex cursor-pointer items-center gap-3 py-1.5 text-sm">
        <span className="flex-1 font-medium text-ink">
          {label}
          {count !== undefined && <span className="ml-1 text-xs text-muted">({count})</span>}
        </span>

        <input
          type="checkbox"
          checked={checked}
          onChange={onToggle}
          className="size-5 shrink-0 appearance-none rounded-md border-2 border-line transition checked:border-violet-600 checked:bg-violet-600"
        />
      </label>
    </li>
  )
}

export function FilterSidebar({
  values,
  categories,
  averageLabel,
  onChange,
  onReset,
}: {
  values: FilterValues
  categories: Category[]
  averageLabel?: string
  onChange: (patch: Partial<FilterValues>) => void
  onReset: () => void
}) {
  const [showAllCategories, setShowAllCategories] = useState(false)
  const visible = showAllCategories ? categories : categories.slice(0, VISIBLE_CATEGORIES)

  return (
    <aside className="w-full shrink-0 space-y-4 lg:w-64">
      <PriceRange
        min={values.min_price}
        max={values.max_price}
        averageLabel={averageLabel}
        onCommit={(range) => onChange({ min_price: range.min, max_price: range.max })}
        onReset={() => onChange({ min_price: '', max_price: '' })}
      />

      <section className="rounded-card bg-white p-4">
        <div className="mb-2 flex items-center justify-between">
          <h2 className="font-bold text-ink">Category</h2>
          <button
            type="button"
            onClick={() => onChange({ category: '' })}
            className="text-xs font-semibold text-muted transition hover:text-violet-700"
          >
            Reset
          </button>
        </div>

        {/* The API filters on a single category, so these behave as radios
            even though they carry the reference's checkbox styling. */}
        <ul>
          {visible.map((category) => (
            <CheckRow
              key={category.id}
              label={category.name}
              count={category.products_count}
              checked={values.category === category.slug}
              onToggle={() =>
                onChange({ category: values.category === category.slug ? '' : category.slug })
              }
            />
          ))}
        </ul>

        {categories.length > VISIBLE_CATEGORIES && (
          <button
            type="button"
            onClick={() => setShowAllCategories((current) => !current)}
            className="mt-2 text-xs font-bold text-violet-600 transition hover:text-violet-700"
          >
            {showAllCategories ? 'Show less' : 'More categories'}
          </button>
        )}
      </section>

      <section className="rounded-card bg-white p-4">
        <h2 className="mb-3 font-bold text-ink">Availability</h2>

        <div className="grid grid-cols-2 gap-1 rounded-pill bg-surface p-1">
          {[
            { label: 'All', value: false },
            { label: 'In stock', value: true },
          ].map((option) => (
            <button
              key={option.label}
              type="button"
              aria-pressed={values.in_stock === option.value}
              onClick={() => onChange({ in_stock: option.value })}
              className={cn(
                'rounded-pill py-2 text-sm font-semibold transition',
                values.in_stock === option.value
                  ? 'bg-violet-600 text-white'
                  : 'text-ink-soft hover:text-violet-700',
              )}
            >
              {option.label}
            </button>
          ))}
        </div>
      </section>

      <section className="rounded-card bg-white p-4">
        <h2 className="mb-3 font-bold text-ink">Sort by</h2>

        <div className="space-y-1">
          {PRODUCT_SORTS.map((sort) => (
            <button
              key={sort}
              type="button"
              aria-pressed={values.sort === sort}
              onClick={() => onChange({ sort })}
              className={cn(
                'w-full rounded-xl px-3 py-2 text-left text-sm font-medium transition',
                values.sort === sort
                  ? 'bg-violet-50 text-violet-700'
                  : 'text-ink-soft hover:bg-surface',
              )}
            >
              {SORT_LABELS[sort]}
            </button>
          ))}
        </div>
      </section>

      <button
        type="button"
        onClick={onReset}
        className="w-full rounded-pill bg-white py-2.5 text-sm font-semibold text-ink-soft transition hover:text-violet-700"
      >
        Reset all filters
      </button>
    </aside>
  )
}
