import { Navigate, useLocation } from 'react-router';
import type { ReactNode } from 'react';
import { useApp } from '../../app/useApp';

/** Redirects guests to /login, remembering where they wanted to go. */
export function RequireAuth({ children }: { children: ReactNode }) {
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

  return <>{children}</>;
}
