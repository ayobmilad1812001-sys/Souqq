/** One place to change the product name, so no component hard-codes it. */
export const BRAND = {
  /** Dark half of the two-tone wordmark. */
  namePrefix: 'Souq',
  /** Violet half. */
  nameSuffix: 'a',
  get name() {
    return this.namePrefix + this.nameSuffix
  },
  tagline: 'Buy and sell across Libya.',
} as const
