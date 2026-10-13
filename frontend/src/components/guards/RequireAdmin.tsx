import { Navigate, useLocation } from 'react-router';
import type { ReactNode } from 'react';
import { useApp } from '../../app/useApp';

/**
 * Admin area guard: guests go to /login with the destination preserved;
 * authenticated non-admin users see a localized forbidden page (a redirect
 * would hide the missing permission as a UI bug); admins proceed.
 */
export function RequireAdmin({ children }: { children: ReactNode }) {
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

  if (user.role !== 'admin' && user.role !== 'superadmin') {
    return (
      <div className="py-16 text-center">
        <h1 className="font-display text-3xl font-bold">
          {t.admin.forbidden_title}
        </h1>
        <p className="mt-3 text-ink-mute">{t.admin.forbidden_body}</p>
      </div>
    );
  }

  return <>{children}</>;
}
