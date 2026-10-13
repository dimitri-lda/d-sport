import { describe, expect, it } from 'vitest';
import { formatMoney } from './format';
import { dictionaries, format } from './dictionaries';

describe('formatMoney', () => {
  it('formats cents in the requested currency and locale', () => {
    expect(formatMoney(11900, 'EUR', 'en')).toBe('€119.00');
    expect(formatMoney(0, 'EUR', 'en')).toBe('€0.00');
    expect(formatMoney(42800, 'PLN', 'pl')).toContain('428');
    expect(formatMoney(34500, 'BYN', 'ru')).toContain('BYN');
  });
});

describe('dictionaries', () => {
  it('provides a complete key set in every locale', () => {
    const shape = (value: unknown): unknown =>
      typeof value === 'object' && value !== null
        ? Object.fromEntries(
            Object.entries(value).map(([key, entry]) => [key, shape(entry)]),
          )
        : 'string';

    expect(shape(dictionaries.ru)).toEqual(shape(dictionaries.en));
    expect(shape(dictionaries.pl)).toEqual(shape(dictionaries.en));
  });

  it('interpolates placeholders', () => {
    expect(format('{count} products', { count: 7 })).toBe('7 products');
    expect(format('Page {page}', { page: 2 })).toBe('Page 2');
  });
});
