import { useEffect, useState } from 'react'

import { formatPrice } from '@/lib/money'

const FLOOR = 0
const CEILING = 2000

/**
 * Dual-thumb range mapped onto the API's min_price / max_price.
 *
 * Two stacked range inputs rather than a custom drag implementation: they are
 * keyboard operable and screen-reader labelled for free, which a div-and-
 * pointer-events version would have to rebuild badly.
 */
export function PriceRange({
  min,
  max,
  averageLabel,
  onCommit,
  onReset,
}: {
  min: string
  max: string
  averageLabel?: string
  onCommit: (range: { min: string; max: string }) => void
  onReset: () => void
}) {
  const [low, setLow] = useState(() => Number(min) || FLOOR)
  const [high, setHigh] = useState(() => Number(max) || CEILING)

  // Keep the thumbs in step when the filters are reset from outside.
  useEffect(() => {
    setLow(Number(min) || FLOOR)
    setHigh(Number(max) || CEILING)
  }, [min, max])

  function commit(nextLow: number, nextHigh: number) {
    onCommit({
      min: nextLow > FLOOR ? nextLow.toFixed(2) : '',
      max: nextHigh < CEILING ? nextHigh.toFixed(2) : '',
    })
  }

  const leftPercent = (low / CEILING) * 100
  const rightPercent = (high / CEILING) * 100

  return (
    <section className="rounded-card bg-white p-4">
      <div className="mb-1 flex items-center justify-between">
        <h2 className="font-bold text-ink">Price Range</h2>
        <button
          type="button"
          onClick={onReset}
          className="text-xs font-semibold text-muted transition hover:text-violet-700"
        >
          Reset
        </button>
      </div>

      {averageLabel && <p className="mb-6 text-xs text-muted">{averageLabel}</p>}

      <div className="mb-3 flex items-center justify-between gap-2">
        <span className="rounded-pill bg-ink px-3 py-1 text-xs font-bold text-white">
          {formatPrice(String(low))}
        </span>
        <span className="rounded-pill bg-ink px-3 py-1 text-xs font-bold text-white">
          {formatPrice(String(high))}
        </span>
      </div>

      <div className="relative h-4">
        <div className="absolute top-1/2 h-1.5 w-full -translate-y-1/2 rounded-pill bg-violet-100" />
        <div
          className="absolute top-1/2 h-1.5 -translate-y-1/2 rounded-pill bg-violet-500"
          style={{ left: `${leftPercent}%`, right: `${100 - rightPercent}%` }}
        />

        <input
          type="range"
          aria-label="Minimum price"
          min={FLOOR}
          max={CEILING}
          step={10}
          value={low}
          onChange={(event) => setLow(Math.min(Number(event.target.value), high - 10))}
          onPointerUp={() => commit(low, high)}
          onKeyUp={() => commit(low, high)}
          className="range-thumb top-1/2 -translate-y-1/2"
        />
        <input
          type="range"
          aria-label="Maximum price"
          min={FLOOR}
          max={CEILING}
          step={10}
          value={high}
          onChange={(event) => setHigh(Math.max(Number(event.target.value), low + 10))}
          onPointerUp={() => commit(low, high)}
          onKeyUp={() => commit(low, high)}
          className="range-thumb top-1/2 -translate-y-1/2"
        />
      </div>
    </section>
  )
}
