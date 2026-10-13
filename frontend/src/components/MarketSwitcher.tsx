import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { updatePreferences } from '../api/context';
import type { Locale } from '../api/context';
import { useApp } from '../app/useApp';

const SELECT_CLASSES =
  'h-9 w-full appearance-none rounded-full border border-dune bg-cream pl-3.5 pr-9 text-sm text-ink ' +
  'transition-colors hover:border-ink-mute/50 focus-visible:outline-2 focus-visible:outline-offset-2 ' +
  'focus-visible:outline-cobalt';

function SelectShell({
  className,
  children,
}: {
  className?: string;
  children: ReactNode;
}) {
  return (
    <div className={`relative ${className ?? ''}`}>
      {children}
      <svg
        aria-hidden="true"
        viewBox="0 0 20 20"
        fill="none"
        stroke="currentColor"
        strokeWidth={1.75}
        className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-mute"
      >
        <path d="M6 8l4 4 4-4" strokeLinecap="round" strokeLinejoin="round" />
      </svg>
    </div>
  );
}

export function MarketSwitcher() {
  const { market } = useApp();
  // Remount when the effective market changes so the controlled selects
  // re-initialize from context instead of drifting out of sync.
  return (
    <MarketSwitcherForm
      key={`${market?.country ?? 'DE'}:${market?.locale ?? 'en'}`}
    />
  );
}

function MarketSwitcherForm() {
  const { t, market, countries, locales, csrfToken, installContext } = useApp();
  const [country, setCountry] = useState(market?.country ?? 'DE');
  const [locale, setLocale] = useState<Locale>(market?.locale ?? 'en');
  const [pending, setPending] = useState(false);
  const [failed, setFailed] = useState(false);

  async function apply(event: FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault();
    if (csrfToken === null) {
      return;
    }

    setPending(true);
    setFailed(false);
    try {
      // The preferences response is the full context payload — install it
      // directly instead of issuing a second context request.
      installContext(await updatePreferences({ country, locale }, csrfToken));
    } catch {
      setFailed(true);
    } finally {
      setPending(false);
    }
  }

  return (
    <form
      onSubmit={(event) => void apply(event)}
      className={`flex flex-wrap items-center gap-2 ${
        failed ? 'sm:flex-wrap' : 'sm:flex-nowrap'
      }`}
    >
      <label className="sr-only" htmlFor="market-country">
        {t.market.country}
      </label>
      <SelectShell className="w-full sm:w-40">
        <select
          id="market-country"
          name="country"
          value={country}
          onChange={(event) => setCountry(event.target.value)}
          className={SELECT_CLASSES}
        >
          {countries.map((country) => (
            <option key={country.code} value={country.code}>
              {country.name}
            </option>
          ))}
        </select>
      </SelectShell>

      <label className="sr-only" htmlFor="market-locale">
        {t.market.language}
      </label>
      <SelectShell className="w-24 sm:w-[4.25rem]">
        <select
          id="market-locale"
          name="locale"
          value={locale}
          onChange={(event) => setLocale(event.target.value as Locale)}
          className={SELECT_CLASSES}
        >
          {locales.map((locale) => (
            <option key={locale} value={locale}>
              {locale.toUpperCase()}
            </option>
          ))}
        </select>
      </SelectShell>

      <button
        type="submit"
        disabled={pending || csrfToken === null}
        className="h-9 shrink-0 rounded-full bg-cobalt px-4 text-sm font-semibold text-cream transition-colors hover:bg-cobalt/90 disabled:opacity-50"
      >
        {pending ? t.market.updating : t.market.apply}
      </button>
      {failed ? (
        <p role="alert" className="basis-full text-xs text-red-700">
          {t.market.update_failed}
        </p>
      ) : null}
    </form>
  );
}
