/**
 * The API carries no product imagery -- there is no image column on `products`.
 * Rather than render a broken <img> or an identical grey box on every card,
 * each product gets a stable generated visual derived from its own id, so the
 * grid reads as designed and a given product always looks the same.
 *
 * Replace this the day the API grows an image field.
 */

const PALETTES = [
  ['#EDE7FF', '#C9BCFF'],
  ['#E4F2FF', '#B9DBFF'],
  ['#FFE9F0', '#FFC2D6'],
  ['#E6FBF3', '#B6EBD8'],
  ['#FFF3E0', '#FFD9A8'],
  ['#F0EEFF', '#D3CCFF'],
] as const

/** Deterministic, well-spread hash so neighbouring ids do not share a palette. */
function hash(value: number): number {
  let x = (value + 0x9e3779b9) | 0
  x = Math.imul(x ^ (x >>> 16), 0x85ebca6b)
  x = Math.imul(x ^ (x >>> 13), 0xc2b2ae35)

  return Math.abs(x ^ (x >>> 16))
}

export function productVisual(id: number, name: string) {
  const seed = hash(id)
  const palette = PALETTES[seed % PALETTES.length] ?? PALETTES[0]
  const angle = seed % 360

  // Two initials read as a monogram and keep the tile from looking empty.
  const initials = name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((word) => word[0]?.toUpperCase() ?? '')
    .join('')

  return {
    initials,
    background: `linear-gradient(${angle}deg, ${palette[0]}, ${palette[1]})`,
  }
}
