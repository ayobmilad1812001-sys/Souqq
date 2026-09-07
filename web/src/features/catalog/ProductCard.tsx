import { Link } from 'react-router-dom'

import type { Product } from '@/api/types'
import { HeartIcon, TagIcon } from '@/components/ui/Icons'
import { cn } from '@/lib/cn'
import { formatPrice } from '@/lib/money'
import { productVisual } from '@/lib/productVisual'
import { useFavouritesStore } from '@/stores/favourites'

export function ProductCard({ product }: { product: Product }) {
  const favourites = useFavouritesStore()
  const isFavourite = favourites.ids.includes(product.id)
  const visual = productVisual(product.id, product.name)

  // "Top item" is derived from real data, not decoration: healthy stock on an
  // active listing. The API exposes no bestseller ranking to use instead.
  const isTopItem = product.is_active && product.stock_quantity >= 150

  return (
    <article className="group relative flex flex-col overflow-hidden rounded-card bg-white transition hover:shadow-[0_14px_40px_-16px_rgb(47_26_112_/_0.35)]">
      <button
        type="button"
        aria-label={isFavourite ? 'Remove from favourites' : 'Add to favourites'}
        aria-pressed={isFavourite}
        onClick={() => favourites.toggle(product.id)}
        className={cn(
          'absolute top-3 right-3 z-10 grid size-9 place-items-center rounded-full transition',
          isFavourite
            ? 'bg-violet-600 text-white'
            : 'bg-white/90 text-ink-soft hover:text-violet-600',
        )}
      >
        <HeartIcon filled={isFavourite} className="size-4.5" />
      </button>

      <Link to={`/products/${product.id}`} className="flex flex-1 flex-col">
        {/* The API has no product imagery, so each card gets a stable
            generated tile keyed on its id. See lib/productVisual.ts. */}
        <div
          className="relative grid aspect-4/3 place-items-center"
          style={{ background: visual.background }}
        >
          <span className="text-4xl font-extrabold text-white/70 select-none">
            {visual.initials}
          </span>

          {isTopItem && (
            <span className="absolute bottom-3 left-1/2 -translate-x-1/2 rounded-pill bg-gold-400 px-3 py-1 text-[11px] font-bold text-ink">
              Top item
            </span>
          )}

          {!product.in_stock && (
            <span className="absolute inset-0 grid place-items-center bg-white/70 text-sm font-bold text-ink-soft">
              Sold out
            </span>
          )}
        </div>

        <div className="flex flex-1 flex-col items-center gap-3 px-4 py-4 text-center">
          <h3 className="line-clamp-1 font-bold text-ink group-hover:text-violet-700">
            {product.name}
          </h3>

          <span className="mt-auto inline-flex items-center gap-1.5 rounded-pill px-4 py-2 text-sm font-bold text-violet-700 ring-1.5 ring-violet-200 transition group-hover:ring-violet-500">
            <TagIcon className="size-4" />
            {formatPrice(product.price)}
          </span>
        </div>
      </Link>
    </article>
  )
}
