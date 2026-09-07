import { Navigate, Route, Routes } from 'react-router-dom'

import { AppLayout } from '@/components/layout/AppLayout'
import { CategoriesPage } from '@/features/admin/CategoriesPage'
import { LoginPage } from '@/features/auth/LoginPage'
import { RegisterPage } from '@/features/auth/RegisterPage'
import { CartPage } from '@/features/cart/CartPage'
import { CatalogPage } from '@/features/catalog/CatalogPage'
import { FavouritesPage } from '@/features/catalog/FavouritesPage'
import { ProductDetailPage } from '@/features/catalog/ProductDetailPage'
import { ForbiddenPage, NotFoundPage } from '@/features/NotFoundPage'
import { OrderDetailPage } from '@/features/orders/OrderDetailPage'
import { OrdersPage } from '@/features/orders/OrdersPage'
import { ProductFormPage } from '@/features/seller/ProductFormPage'
import { SellerProductsPage } from '@/features/seller/SellerProductsPage'
import { StatsPage } from '@/features/seller/StatsPage'
import { useBootstrapAuth } from '@/hooks/useAuth'
import { ProtectedRoute, RoleRoute } from '@/routes/guards'

export default function App() {
  // Validates a stored token once, before the guards make a decision.
  useBootstrapAuth()

  return (
    <Routes>
      <Route element={<AppLayout />}>
        {/* Public */}
        <Route index element={<CatalogPage />} />
        <Route path="products/:id" element={<ProductDetailPage />} />
        <Route path="login" element={<LoginPage />} />
        <Route path="register" element={<RegisterPage />} />
        <Route path="forbidden" element={<ForbiddenPage />} />
        {/* Favourites live in localStorage, so no session is required. */}
        <Route path="favourites" element={<FavouritesPage />} />

        {/* Any signed-in user */}
        <Route
          path="cart"
          element={
            <ProtectedRoute>
              <CartPage />
            </ProtectedRoute>
          }
        />
        <Route
          path="orders"
          element={
            <ProtectedRoute>
              <OrdersPage />
            </ProtectedRoute>
          }
        />
        <Route
          path="orders/:id"
          element={
            <ProtectedRoute>
              <OrderDetailPage />
            </ProtectedRoute>
          }
        />

        {/* Seller and admin -- mirrors the API's role:seller,admin middleware */}
        <Route
          path="seller"
          element={
            <RoleRoute roles={['seller', 'admin']}>
              <StatsPage />
            </RoleRoute>
          }
        />
        <Route
          path="seller/products"
          element={
            <RoleRoute roles={['seller', 'admin']}>
              <SellerProductsPage />
            </RoleRoute>
          }
        />
        <Route
          path="seller/products/:id"
          element={
            <RoleRoute roles={['seller', 'admin']}>
              <ProductFormPage />
            </RoleRoute>
          }
        />

        {/* Admin only */}
        <Route path="admin" element={<Navigate to="/admin/categories" replace />} />
        <Route
          path="admin/categories"
          element={
            <RoleRoute roles={['admin']}>
              <CategoriesPage />
            </RoleRoute>
          }
        />

        <Route path="*" element={<NotFoundPage />} />
      </Route>
    </Routes>
  )
}
