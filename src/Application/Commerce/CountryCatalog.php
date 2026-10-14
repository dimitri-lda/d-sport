<?php

declare(strict_types=1);

namespace App\Application\Commerce;

/**
 * Resolves a country code into market/currency/locale/VAT parameters using the
 * injected commerce configuration. Dedicated markets (BY, PL) win; any
 * configured EU country falls back to the shared eu_global market with EUR and
 * that country's VAT rate; anything else resolves to the default country.
 */
final class CountryCatalog
{
    /**
     * @param array<string, array{code: string, currency: string, locale: string, vat_basis_points: int}> $markets
     * @param array<string, int>                                                                          $euVatBasisPoints
     * @param array<string, array<string, string>>                                                        $countryNames
     * @param list<string>                                                                                $locales
     */
    public function __construct(
        private readonly array $markets,
        private readonly array $euVatBasisPoints,
        private readonly array $countryNames,
        private readonly array $locales,
        private readonly string $defaultCountry,
        private readonly string $fallbackMarket,
    ) {
    }

    /** @return array{country: string, market: string, currency: string, locale: string, vat_basis_points: int} */
    public function resolve(string $country, ?string $locale): array
    {
        $country = strtoupper($country);
        $default = strtoupper($this->defaultCountry);
        if (!$this->isSupported($default)) {
            // A misconfigured default falls back to 'DE' — but only when
            // 'DE' itself is configured; otherwise fail loudly instead of
            // hitting an undefined-array-key on the VAT map below.
            if (!$this->isSupported('DE')) {
                throw new \RuntimeException(sprintf('Commerce misconfiguration: neither the default country "%s" nor the "DE" fallback is configured.', $default));
            }
            $default = 'DE';
        }
        if (!$this->isSupported($country)) {
            $country = $default;
        }

        $market = $this->markets[$country] ?? null;
        if (null !== $market) {
            return [
                'country' => $country,
                'market' => $market['code'],
                'currency' => $market['currency'],
                'locale' => $this->resolveLocale($locale, $market['locale']),
                'vat_basis_points' => $market['vat_basis_points'],
            ];
        }

        return [
            'country' => $country,
            'market' => $this->fallbackMarket,
            'currency' => 'EUR',
            'locale' => $this->resolveLocale($locale, 'en'),
            'vat_basis_points' => $this->euVatBasisPoints[$country],
        ];
    }

    public function isSupported(string $country): bool
    {
        $country = strtoupper($country);

        return isset($this->markets[$country]) || isset($this->euVatBasisPoints[$country]);
    }

    public function isSupportedLocale(string $locale): bool
    {
        return \in_array($locale, $this->locales, true);
    }

    /** @return list<string> */
    public function locales(): array
    {
        return $this->locales;
    }

    /** @return list<array{code: string, name: string}> */
    public function countries(string $locale): array
    {
        $countries = [];
        foreach ($this->countryNames as $code => $names) {
            $countries[] = [
                'code' => $code,
                'name' => $names[$locale] ?? $names['en'] ?? $code,
            ];
        }

        return $countries;
    }

    private function resolveLocale(?string $locale, string $marketLocale): string
    {
        if (null !== $locale && $this->isSupportedLocale($locale)) {
            return $locale;
        }

        return $marketLocale;
    }
}
