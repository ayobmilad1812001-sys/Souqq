/**
 * Money helpers.
 *
 * The API emits every monetary value as a decimal STRING ("366.33"), never a
 * JSON number. That is deliberate: a JSON number would be parsed into a
 * double, and 0.1 + 0.2 !== 0.3. The backend keeps money in integer minor
 * units all the way to the response; these helpers keep that property here.
 *
 * RULE: never parseFloat a price in order to calculate with it.
 * Displaying is fine. Arithmetic must go through minor units.
 */

/** "366.33" -> 36633 */
export function toMinor(decimal: string): number {
  const [whole = '0', fraction = ''] = decimal.trim().split('.')
  const negative = whole.startsWith('-')
  const magnitude =
    Math.abs(Number(whole)) * 100 + Number(fraction.padEnd(2, '0').slice(0, 2))

  return negative ? -magnitude : magnitude
}

/** 36633 -> "366.33" */
export function toDecimal(minor: number): string {
  const sign = minor < 0 ? '-' : ''
  const absolute = Math.abs(Math.round(minor))

  return `${sign}${Math.floor(absolute / 100)}.${String(absolute % 100).padStart(2, '0')}`
}

/** Exact addition over decimal strings. */
export function addMoney(...amounts: string[]): string {
  return toDecimal(amounts.reduce((total, amount) => total + toMinor(amount), 0))
}

/** Exact subtraction: a - b. */
export function subtractMoney(a: string, b: string): string {
  return toDecimal(toMinor(a) - toMinor(b))
}

/** Exact multiplication by a whole quantity. You cannot buy 2.5 phones. */
export function multiplyMoney(amount: string, quantity: number): string {
  return toDecimal(toMinor(amount) * Math.trunc(quantity))
}

export function compareMoney(a: string, b: string): number {
  return toMinor(a) - toMinor(b)
}

export function isZeroMoney(amount: string): boolean {
  return toMinor(amount) === 0
}

/**
 * Display only. Never feed the output of this back into a calculation.
 * Libya uses the dinar, which is conventionally quoted to three decimals; the
 * API stores DECIMAL(10,2), so two is the honest precision to show.
 */
export function formatPrice(decimal: string, currency = 'LYD'): string {
  const value = Number(decimal)

  if (!Number.isFinite(value)) {
    return decimal
  }

  return new Intl.NumberFormat('en-LY', {
    style: 'currency',
    currency,
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(value)
}
