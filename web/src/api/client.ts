import type { PaginationMeta } from './types'

const BASE_URL = import.meta.env.VITE_API_URL ?? 'http://localhost:8000/api/v1'

const TOKEN_KEY = 'libyamarket.token'

/* ------------------------------------------------------------------------ */
/* Token storage                                                             */
/* ------------------------------------------------------------------------ */
/*
 * localStorage is readable by any XSS on this origin. The alternative --
 * memory only -- logs the user out on refresh and does NOT actually stop a
 * determined XSS, which can simply call the API while the page is open. The
 * real defence is not having XSS: React escapes by default, so never pass
 * user-supplied content to dangerouslySetInnerHTML.
 *
 * Reads are wrapped because Safari private mode throws on access.
 */
export function getToken(): string | null {
  try {
    return localStorage.getItem(TOKEN_KEY)
  } catch {
    return null
  }
}

export function setToken(token: string): void {
  try {
    localStorage.setItem(TOKEN_KEY, token)
  } catch {
    /* Storage unavailable; the session simply will not survive a refresh. */
  }
}

export function clearToken(): void {
  try {
    localStorage.removeItem(TOKEN_KEY)
  } catch {
    /* nothing to do */
  }
}

/* ------------------------------------------------------------------------ */
/* Errors                                                                    */
/* ------------------------------------------------------------------------ */

export class ApiError extends Error {
  readonly status: number

  readonly errors: Record<string, unknown>

  constructor(status: number, message: string, errors: Record<string, unknown> = {}) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.errors = errors
  }

  /** 422 -- the payload is wrong. Retrying it unchanged fails identically. */
  get isValidation(): boolean {
    return this.status === 422
  }

  /**
   * 409 -- the payload was fine but conflicts with current state: stock ran
   * out, an illegal status transition, the cancellation window closed. A
   * different retry may well succeed.
   */
  get isConflict(): boolean {
    return this.status === 409
  }

  get isUnauthorized(): boolean {
    return this.status === 401
  }

  get isForbidden(): boolean {
    return this.status === 403
  }

  get isNotFound(): boolean {
    return this.status === 404
  }

  /** Login is capped at 6/min, checkout at 10/min, everything else 120/min. */
  get isRateLimited(): boolean {
    return this.status === 429
  }

  /** Field errors flattened for react-hook-form's setError. */
  fieldErrors(): Record<string, string> {
    const flattened: Record<string, string> = {}

    for (const [field, messages] of Object.entries(this.errors)) {
      if (Array.isArray(messages) && typeof messages[0] === 'string') {
        flattened[field] = messages[0]
      } else if (typeof messages === 'string') {
        flattened[field] = messages
      }
    }

    return flattened
  }

  /**
   * Stock figures attached to a 409 from the cart or checkout. The API reports
   * `available` while holding the row lock, so it is authoritative, not a
   * stale number from a listing page.
   */
  stockConflict(): { requested: number; available: number } | null {
    const { requested, available } = this.errors as {
      requested?: number
      available?: number
    }

    return typeof requested === 'number' && typeof available === 'number'
      ? { requested, available }
      : null
  }
}

/* ------------------------------------------------------------------------ */
/* The client                                                                */
/* ------------------------------------------------------------------------ */

interface Envelope<T> {
  success: boolean
  data: T
  message: string
  errors?: Record<string, unknown>
  meta?: PaginationMeta
}

export interface ApiResult<T> {
  data: T
  message: string
  meta?: PaginationMeta
}

/** Called on any 401 so the app can drop its session. Wired up in main.tsx. */
let onUnauthorized: (() => void) | null = null

export function setUnauthorizedHandler(handler: () => void): void {
  onUnauthorized = handler
}

interface RequestOptions extends Omit<RequestInit, 'body'> {
  body?: unknown
  query?: Record<string, unknown>
}

function buildUrl(path: string, query?: Record<string, unknown>): string {
  if (!query) {
    return `${BASE_URL}${path}`
  }

  const params = new URLSearchParams()

  for (const [key, value] of Object.entries(query)) {
    if (value === undefined || value === null || value === '') {
      continue
    }
    // The API reads booleans as 1/0 in the query string.
    params.set(key, typeof value === 'boolean' ? (value ? '1' : '0') : String(value))
  }

  const queryString = params.toString()

  return queryString ? `${BASE_URL}${path}?${queryString}` : `${BASE_URL}${path}`
}

/**
 * The single door to the API.
 *
 * Unwraps the { success, data, message } envelope here, exactly once, so no
 * component ever writes `response.data.data`. `meta` is lifted out alongside
 * `data` rather than nested, matching how the API sends it.
 */
export async function api<T>(path: string, options: RequestOptions = {}): Promise<ApiResult<T>> {
  const { body, query, headers, ...rest } = options
  const token = getToken()

  let response: Response

  try {
    response = await fetch(buildUrl(path, query), {
      ...rest,
      headers: {
        Accept: 'application/json',
        ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
        ...headers,
      },
      ...(body === undefined ? {} : { body: JSON.stringify(body) }),
    })
  } catch {
    // fetch only rejects on a genuine network/CORS failure, never on 4xx/5xx.
    throw new ApiError(0, 'Could not reach the server. Check your connection.')
  }

  const payload = (await response.json().catch(() => null)) as Envelope<T> | null

  if (!response.ok) {
    if (response.status === 401) {
      clearToken()
      onUnauthorized?.()
    }

    throw new ApiError(
      response.status,
      payload?.message ?? 'Something went wrong.',
      payload?.errors ?? {},
    )
  }

  return {
    data: payload?.data as T,
    message: payload?.message ?? '',
    meta: payload?.meta,
  }
}
