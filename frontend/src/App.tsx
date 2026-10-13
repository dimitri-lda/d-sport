import { Route, Routes } from 'react-router';
import { AppProvider } from './app/AppContext';
import { StoreLayout } from './components/StoreLayout';
import { RequireAuth } from './components/guards/RequireAuth';
import { RequireGuest } from './components/guards/RequireGuest';
import { HomePage } from './pages/HomePage';
import { CatalogPage } from './pages/CatalogPage';
import { ProductPage } from './pages/ProductPage';
import { LoginPage } from './pages/LoginPage';
import { RegisterPage } from './pages/RegisterPage';
import { ForgotPasswordPage } from './pages/ForgotPasswordPage';
import { ResetPasswordPage } from './pages/ResetPasswordPage';
import { VerifyEmailPage } from './pages/VerifyEmailPage';
import { ConfirmPasswordPage } from './pages/ConfirmPasswordPage';
import { ProfilePage } from './pages/ProfilePage';
import { CartPage } from './pages/CartPage';
import { CheckoutPage } from './pages/CheckoutPage';
import { OrderPage } from './pages/OrderPage';
import { OrdersPage } from './pages/OrdersPage';
import { RequireVerified } from './components/guards/RequireVerified';
import { RequireAdmin } from './components/guards/RequireAdmin';
import { AdminLayout } from './components/AdminLayout';
import { AdminOrdersPage } from './pages/admin/AdminOrdersPage';
import { AdminOrderPage } from './pages/admin/AdminOrderPage';
import { NotFoundPage } from './pages/NotFoundPage';

export function App() {
  return (
    <AppProvider>
      <Routes>
        <Route element={<StoreLayout />}>
          <Route index element={<HomePage />} />
          <Route path="shop" element={<CatalogPage />} />
          <Route path="shop/:slug" element={<ProductPage />} />
          <Route
            path="login"
            element={
              <RequireGuest>
                <LoginPage />
              </RequireGuest>
            }
          />
          <Route
            path="register"
            element={
              <RequireGuest>
                <RegisterPage />
              </RequireGuest>
            }
          />
          <Route path="forgot-password" element={<ForgotPasswordPage />} />
          <Route path="reset-password" element={<ResetPasswordPage />} />
          <Route path="verify-email" element={<VerifyEmailPage />} />
          <Route
            path="confirm-password"
            element={
              <RequireAuth>
                <ConfirmPasswordPage />
              </RequireAuth>
            }
          />
          <Route
            path="profile"
            element={
              <RequireAuth>
                <ProfilePage />
              </RequireAuth>
            }
          />
          <Route path="cart" element={<CartPage />} />
          <Route path="checkout" element={<CheckoutPage />} />
          <Route path="orders/:number" element={<OrderPage />} />
          <Route
            path="account/orders"
            element={
              <RequireVerified>
                <OrdersPage />
              </RequireVerified>
            }
          />
          <Route path="*" element={<NotFoundPage />} />
        </Route>
        <Route
          element={
            <RequireAdmin>
              <AdminLayout />
            </RequireAdmin>
          }
        >
          <Route path="admin/orders" element={<AdminOrdersPage />} />
          <Route path="admin/orders/:id" element={<AdminOrderPage />} />
        </Route>
      </Routes>
    </AppProvider>
  );
}
