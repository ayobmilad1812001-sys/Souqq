/**
 * TypeScript mirrors of the API's Resource classes.
 *
 * Fields marked optional are genuinely ABSENT from the payload, not null: the
 * API uses whenLoaded() / whenCounted() / when(), which omit the key entirely
 * when the relation was not eager loaded or the aggregate not requested.
 */

export type Role = 'customer' | 'seller' | 'admin'

export type OrderStatus =
  | 'pending'
  | 'confirmed'
  | 'processing'
  | 'shipped'
  | 'delivered'
  | 'cancelled'

export interface User {
  id: number
  name: string
  email: string
  role: Role
  created_at: string
}

export interface Category {
  id: number
  name: string
  slug: string
  /** Only present when the endpoint applied withCount('products'). */
  products_count?: number
  created_at: string
}

export interface Product {
  id: number
  name: string
  description: string
  /** A decimal STRING, never a number. See lib/money.ts for why. */
  price: string
  sku: string
  stock_quantity: number
  in_stock: boolean
  is_active: boolean
  category?: Category
  seller?: { id: number; name: string }
  reviews_count?: number
  /** Detail endpoint ONLY -- absent from listings. */
  average_rating?: string
  created_at: string
  updated_at: string
}

export interface CartItem {
  id: number
  quantity: number
  /** Today's catalogue price -- a cart is priced live. */
  unit_price: string
  line_total: string
  product?: Product
}

export interface Cart {
  id: number
  items: CartItem[]
  items_count: number
  total_quantity: number
  subtotal: string
  estimated_shipping: string
  estimated_total: string
  updated_at: string
}

export interface OrderItem {
  id: number
  product_id: number
  quantity: number
  /** The historical price, frozen at checkout. Not today's price. */
  unit_price: string
  subtotal: string
  product?: Product
}

export interface Order {
  id: number
  status: OrderStatus
  /** The legal next states. Drive the UI from this, never a hard-coded map. */
  allowed_transitions: OrderStatus[]
  subtotal: string
  shipping_cost: string
  total: string
  items?: OrderItem[]
  items_count?: number
  customer?: User
  cancelled_at: string | null
  created_at: string
}

export interface Review {
  id: number
  rating: number
  comment: string | null
  author?: { id: number; name: string }
  created_at: string
}

export interface SellerStats {
  scope: 'seller'
  products_total: number
  products_active: number
  out_of_stock: number
  units_sold: number
  gross_revenue: string
}

export interface PlatformStats {
  scope: 'platform'
  users_total: number
  sellers_total: number
  products_total: number
  orders_total: number
  orders_by_status: Partial<Record<OrderStatus, number>>
  gross_revenue: string
}

export type Stats = SellerStats | PlatformStats

export interface PaginationMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface AuthPayload {
  user: User
  token: string
}

/** Query parameters accepted by GET /products. */
export interface ProductFilters {
  search?: string
  /** Accepts a category id or a slug. */
  category?: string | number
  min_price?: string
  max_price?: string
  in_stock?: boolean
  /** Seller's own inventory, including inactive products. Requires auth. */
  mine?: boolean
  sort?: ProductSort
  per_page?: number
  page?: number
}

/** Whitelisted server-side. Anything else is a 422. */
export const PRODUCT_SORTS = [
  'newest',
  'oldest',
  'price_asc',
  'price_desc',
  'name_asc',
  'name_desc',
] as const

export type ProductSort = (typeof PRODUCT_SORTS)[number]

export const SORT_LABELS: Record<ProductSort, string> = {
  newest: 'Newest first',
  oldest: 'Oldest first',
  price_asc: 'Price: low to high',
  price_desc: 'Price: high to low',
  name_asc: 'Name: A to Z',
  name_desc: 'Name: Z to A',
}
