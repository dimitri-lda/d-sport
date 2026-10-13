import { Navigate, useLocation } from 'react-router';
import type { ReactNode } from 'react';
import { useApp } from '../../app/useApp';

/** Redirects authenticated visitors away from guest-only pages. */
export function RequireGuest({ children }: { children: ReactNode }) {
  const { ready, user, t } = useApp();
  const location = useLocation();

  if (!ready) {
    return (
      <p className="py-16 text-center text-ink-mute">{t.common.loading}</p>
    );
  }

  if (user !== null) {
    const from =
      typeof location.state === 'object' &&
      location.state !== null &&
      'from' in location.state &&
      typeof location.state.from === 'string'
        ? location.state.from
        : '/profile';

    return <Navigate to={from} replace />;
  }

  return <>{children}</>;
}
