import type { Locale } from '../../api/context';

const INTL_LOCALES: Record<Locale, string> = {
  en: 'en-GB',
  ru: 'ru-RU',
  pl: 'pl-PL',
};

/** Formats integer cents as a localized currency string. */
export function formatMoney(
  cents: number,
  currency: string,
  locale: Locale,
): string {
  return new Intl.NumberFormat(INTL_LOCALES[locale] ?? INTL_LOCALES.en, {
    style: 'currency',
    currency,
  }).format(cents / 100);
}
