import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, NavLink, Outlet, useNavigate } from 'react-router';
import { CART_QUERY_KEY, getCart } from '../api/cart';
import { useApp } from '../app/useApp';
import { format } from '../lib/i18n/dictionaries';
import { formatMoney } from '../lib/i18n/format';
import { MarketSwitcher } from './MarketSwitcher';
import { BackendStatus } from './BackendStatus';

function navLinkClass({ isActive }: { isActive: boolean }): string {
  return [
    'rounded-full px-4 py-2 text-sm font-medium transition-colors',
    isActive
      ? 'bg-ink text-cream'
      : 'text-ink-mute hover:bg-sand hover:text-ink',
  ].join(' ');
}

export function StoreLayout() {
  const { t, ready, failed, retryContext, user, market, locale, signOut } =
    useApp();
  const [menuOpen, setMenuOpen] = useState(false);
  const navigate = useNavigate();
  const cart = useQuery({
    queryKey: CART_QUERY_KEY,
    queryFn: ({ signal }) => getCart(signal),
    enabled: ready,
  });
  const cartCount = cart.data?.count ?? 0;

  async function onLogout(): Promise<void> {
    setMenuOpen(false);
    try {
      await signOut();
    } finally {
      void navigate('/', { replace: true });
    }
  }

  const authLinks = (
    <>
      <NavLink
        to="/cart"
        className={navLinkClass}
        onClick={() => setMenuOpen(false)}
      >
        {t.nav.cart}
        {cartCount > 0 ? (
          <span
            className="ml-1.5 inline-flex h-5 min-w-5 items-center
              justify-center rounded-full bg-cobalt px-1 text-xs
              font-semibold text-cream"
          >
            {cartCount}
          </span>
        ) : null}
      </NavLink>
      {user === null ? (
        <NavLink
          to="/login"
          className={navLinkClass}
          onClick={() => setMenuOpen(false)}
        >
          {t.nav.login}
        </NavLink>
      ) : (
        <>
          <NavLink
            to="/account/orders"
            className={navLinkClass}
            onClick={() => setMenuOpen(false)}
          >
            {t.nav.orders}
          </NavLink>
          <NavLink
            to="/profile"
            className={(state) =>
              `${navLinkClass(state)} inline-flex items-center gap-2`
            }
            onClick={() => setMenuOpen(false)}
          >
            {user.display_name}
            <span
              className={`inline-block h-2 w-2 shrink-0 rounded-full ${
                user.email_verified ? 'bg-cobalt' : 'bg-dune'
              }`}
              title={user.email_verified ? t.nav.verified : t.nav.unverified}
              aria-label={
                user.email_verified ? t.nav.verified : t.nav.unverified
              }
            />
          </NavLink>
          <button
            type="button"
            className="rounded-full px-4 py-2 text-left text-sm font-medium
              text-ink-mute transition-colors hover:bg-sand hover:text-ink"
            onClick={() => void onLogout()}
          >
            {t.nav.logout}
          </button>
        </>
      )}
    </>
  );

  return (
    <div className="flex min-h-screen flex-col">
      <a
        href="#main"
        className="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:m-3
          focus:rounded-md focus:bg-ink focus:px-4 focus:py-2 focus:text-cream"
      >
        {t.nav.skip_to_content}
      </a>

      <div className="hidden border-b border-dune/60 bg-ink text-cream/70 sm:block">
        <ul className="mx-auto flex max-w-6xl items-center justify-center gap-8 px-4 py-1.5 text-xs sm:px-6">
          {market === null ? null : (
            <>
              <li>
                {format(t.nav.trust_delivery, {
                  amount: formatMoney(
                    market.free_standard_threshold,
                    market.currency,
                    locale,
                  ),
                })}
              </li>
              <li aria-hidden="true" className="text-cream/30">
                ·
              </li>
            </>
          )}
          <li>{t.nav.trust_express}</li>
          <li aria-hidden="true" className="text-cream/30">
            ·
          </li>
          <li>{t.nav.trust_vat}</li>
        </ul>
      </div>

      <header className="sticky top-0 z-40 border-b border-dune/70 bg-cream/85 backdrop-blur">
        <div className="mx-auto flex max-w-6xl items-center gap-4 px-4 py-3 sm:px-6">
          <Link
            to="/"
            className="flex min-w-0 items-baseline gap-2 whitespace-nowrap"
          >
            <span className="shrink-0 font-display text-xl font-bold tracking-tight">
              Sport E
            </span>
            <span className="hidden min-w-0 truncate text-xs text-ink-mute sm:inline">
              {t.nav.brand_tagline}
            </span>
          </Link>

          <nav
            aria-label="Main"
            className="ml-auto hidden shrink-0 items-center gap-1 lg:flex"
          >
            <NavLink to="/" end className={navLinkClass}>
              {t.nav.home}
            </NavLink>
            <NavLink to="/shop" className={navLinkClass}>
              {t.nav.shop}
            </NavLink>
            {authLinks}
          </nav>

          <button
            type="button"
            className="ml-auto rounded-md border border-dune px-3 py-2 text-sm lg:hidden"
            aria-expanded={menuOpen}
            aria-controls="mobile-nav"
            onClick={() => setMenuOpen((open) => !open)}
          >
            ☰
          </button>
        </div>

        {menuOpen ? (
          <nav
            id="mobile-nav"
            aria-label="Main"
            className="border-t border-dune/70 px-4 py-3 lg:hidden"
          >
            <div className="flex flex-col gap-2">
              <NavLink
                to="/"
                end
                className={navLinkClass}
                onClick={() => setMenuOpen(false)}
              >
                {t.nav.home}
              </NavLink>
              <NavLink
                to="/shop"
                className={navLinkClass}
                onClick={() => setMenuOpen(false)}
              >
                {t.nav.shop}
              </NavLink>
              {authLinks}
            </div>
          </nav>
        ) : null}
      </header>

      <main id="main" className="mx-auto w-full max-w-6xl flex-1 px-4 sm:px-6">
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
              className="mt-4 rounded-full bg-cobalt px-6 py-2 text-sm font-semibold
                text-cream"
            >
              {t.common.retry}
            </button>
          </div>
        ) : (
          <p className="py-16 text-center text-ink-mute">{t.common.loading}</p>
        )}
      </main>

      <footer className="mt-16 bg-ink text-cream">
        <div className="mx-auto max-w-6xl px-4 py-8 sm:px-6">
          <div className="flex flex-col gap-1">
            <p className="font-display text-lg font-semibold tracking-tight">
              Sport E
            </p>
            <p className="max-w-md text-xs text-cream/60">
              {t.nav.brand_tagline}
            </p>
          </div>

          <div className="mt-8 flex flex-col gap-6 border-t border-cream/15 pt-5 lg:flex-row lg:items-end lg:justify-between">
            <details className="text-xs text-cream/60">
              <summary className="cursor-pointer select-none transition-colors hover:text-cream">
                Development
              </summary>
              <div className="mt-3 max-w-md">
                <BackendStatus />
              </div>
            </details>

            <div className="w-full lg:w-auto">
              <MarketSwitcher />
            </div>
          </div>
        </div>
      </footer>
    </div>
  );
}
