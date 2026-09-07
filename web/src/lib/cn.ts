/** Join class names, dropping falsy entries. Small enough not to need clsx. */
export function cn(...classes: Array<string | false | null | undefined>): string {
  return classes.filter(Boolean).join(' ')
}
