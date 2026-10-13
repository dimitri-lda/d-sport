import { Navigate, useLocation } from 'react-router';
import type { ReactNode } from 'react';
import { useApp } from '../../app/useApp';

/**
 * Requires an authenticated, email-verified account: guests go to /login
 * (destination preserved), unverified users to /verify-email.
 */
export function RequireVerified({ children }: { children: ReactNode }) {
  const { ready, user, t } = useApp();
  const location = useLocation();

  if (!ready) {
    return (
      <p className="py-16 text-center text-ink-mute">{t.common.loading}</p>
    );
  }

  if (user === null) {
    return <Navigate to="/login" state={{ from: location.pathname }} replace />;
  }

  if (!user.email_verified) {
    return <Navigate to="/verify-email" replace />;
  }

  return <>{children}</>;
}
