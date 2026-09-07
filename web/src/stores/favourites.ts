import { create } from 'zustand'

/**
 * Favourites are stored in this browser only.
 *
 * The API has no favourites endpoint, so there is nothing to sync to. Keeping
 * them local is honest: they survive a refresh, they do not follow the user to
 * another device, and no component pretends otherwise. If the API grows a
 * favourites resource, only this file changes.
 */
const STORAGE_KEY = 'souqa.favourites'

function read(): number[] {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    const parsed: unknown = raw ? JSON.parse(raw) : []

    return Array.isArray(parsed) ? parsed.filter((id) => typeof id === 'number') : []
  } catch {
    // Private browsing can throw on access, and stored JSON can be corrupt.
    return []
  }
}

function write(ids: number[]): void {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(ids))
  } catch {
    /* Storage unavailable; favourites simply will not persist. */
  }
}

interface FavouritesState {
  ids: number[]
  toggle: (id: number) => void
  has: (id: number) => boolean
}

export const useFavouritesStore = create<FavouritesState>((set, get) => ({
  ids: read(),

  toggle: (id) =>
    set((state) => {
      const ids = state.ids.includes(id)
        ? state.ids.filter((current) => current !== id)
        : [...state.ids, id]

      write(ids)

      return { ids }
    }),

  has: (id) => get().ids.includes(id),
}))

export const useFavouriteCount = () => useFavouritesStore((state) => state.ids.length)
