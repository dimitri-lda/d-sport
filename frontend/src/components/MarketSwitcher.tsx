import { useState } from 'react';
import type { FormEvent } from 'react';
import { updatePreferences } from '../api/context';
import type { Locale } from '../api/context';
import { useApp } from '../app/useApp';

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
      className="flex flex-wrap items-center gap-2"
    >
      <label className="sr-only" htmlFor="market-country">
        {t.market.country}
      </label>
      <select
        id="market-country"
        name="country"
        value={country}
        onChange={(event) => setCountry(event.target.value)}
        className="rounded-md border border-dune bg-cream px-2 py-1.5 text-sm"
      >
        {countries.map((country) => (
          <option key={country.code} value={country.code}>
            {country.name}
          </option>
        ))}
      </select>

      <label className="sr-only" htmlFor="market-locale">
        {t.market.language}
      </label>
      <select
        id="market-locale"
        name="locale"
        value={locale}
        onChange={(event) => setLocale(event.target.value as Locale)}
        className="rounded-md border border-dune bg-cream px-2 py-1.5 text-sm"
      >
        {locales.map((locale) => (
          <option key={locale} value={locale}>
            {locale.toUpperCase()}
          </option>
        ))}
      </select>

      <button
        type="submit"
        disabled={pending || csrfToken === null}
        className="rounded-md bg-cobalt px-3 py-1.5 text-sm font-semibold text-cream disabled:opacity-50"
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
