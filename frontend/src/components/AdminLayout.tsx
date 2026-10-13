import { useState } from 'react';
import { Link, NavLink, Outlet, useNavigate } from 'react-router';
import { useApp } from '../app/useApp';

function navLinkClass({ isActive }: { isActive: boolean }): string {
  return [
    'block rounded-lg px-4 py-2 text-sm font-medium transition-colors',
    isActive ? 'bg-cream/15 text-cream' : 'text-cream/60 hover:text-cream',
  ].join(' ');
}

/**
 * Back-office shell: dark ink sidebar with admin nav, distinct from the
 * storefront. Content area keeps the light store palette.
 */
export function AdminLayout() {
  const { t, ready, failed, retryContext, user, signOut } = useApp();
  const [menuOpen, setMenuOpen] = useState(false);
  const navigate = useNavigate();

  async function onLogout(): Promise<void> {
    setMenuOpen(false);
    try {
      await signOut();
    } finally {
      void navigate('/', { replace: true });
    }
  }

  const sidebarNav = (
    <>
      <NavLink
        to="/admin/orders"
        className={navLinkClass}
        onClick={() => setMenuOpen(false)}
      >
        {t.admin.nav_orders}
      </NavLink>
    </>
  );

  const sidebar = (
    <div className="flex h-full flex-col gap-6 p-5">
      <Link
        to="/admin/orders"
        className="flex items-baseline gap-2"
        onClick={() => setMenuOpen(false)}
      >
        <span className="font-display text-xl font-bold tracking-tight text-cream">
          Sport E
        </span>
        <span className="text-xs font-medium uppercase tracking-wide text-cream/60">
          {t.admin.brand_suffix}
        </span>
      </Link>

      <nav aria-label={t.admin.nav_label} className="flex flex-col gap-1">
        {sidebarNav}
      </nav>

      <div className="mt-auto flex flex-col gap-3 border-t border-cream/15 pt-4">
        <Link
          to="/"
          className="text-sm font-medium text-cream/60 transition-colors
            hover:text-cream"
        >
          {t.admin.back_to_store}
        </Link>
        {user !== null ? (
          <div className="flex items-center justify-between gap-2">
            <p className="truncate text-sm text-cream/80">
              {user.display_name}
            </p>
            <button
              type="button"
              onClick={() => void onLogout()}
              className="rounded-full border border-cream/25 px-3 py-1 text-xs
                font-medium text-cream/80 transition-colors hover:text-cream"
            >
              {t.nav.logout}
            </button>
          </div>
        ) : null}
      </div>
    </div>
  );

  return (
    <div className="flex min-h-screen flex-col sm:flex-row">
      {/* Mobile topbar */}
      <header
        className="sticky top-0 z-40 flex items-center gap-3 bg-ink px-4 py-3
          sm:hidden"
      >
        <Link to="/admin/orders" className="flex items-baseline gap-2">
          <span className="font-display text-lg font-bold text-cream">
            Sport E
          </span>
          <span className="text-xs font-medium uppercase tracking-wide text-cream/60">
            {t.admin.brand_suffix}
          </span>
        </Link>
        <button
          type="button"
          className="ml-auto rounded-md border border-cream/25 px-3 py-1.5
            text-sm text-cream"
          aria-expanded={menuOpen}
          aria-controls="admin-mobile-nav"
          onClick={() => setMenuOpen((open) => !open)}
        >
          ☰
        </button>
      </header>
      {menuOpen ? (
        <nav
          id="admin-mobile-nav"
          aria-label={t.admin.nav_label}
          className="bg-ink px-4 pb-4 sm:hidden"
        >
          {sidebar}
        </nav>
      ) : null}

      {/* Desktop sidebar */}
      <aside className="hidden w-56 shrink-0 bg-ink sm:block">
        <div className="sticky top-0 h-screen">{sidebar}</div>
      </aside>

      <main className="min-w-0 flex-1 px-4 py-6 sm:px-8">
        {ready ? (
          <Outlet />
        ) : failed ? (
          <div role="alert" className="py-16 text-center">
            <p className="font-display text-xl font-semibold">
              {t.common.error}
            </p>
            <button
              type="button"
              onClick={retryContext}
              className="mt-4 rounded-full bg-cobalt px-6 py-2 text-sm
                font-semibold text-cream"
            >
              {t.common.retry}
            </button>
          </div>
        ) : (
          <p className="py-16 text-center text-ink-mute">{t.common.loading}</p>
        )}
      </main>
    </div>
  );
}
