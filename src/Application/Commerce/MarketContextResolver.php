<?php

declare(strict_types=1);

namespace App\Application\Commerce;

use App\Domain\Commerce\MarketContext;
use App\Presentation\Http\ApiProblem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Effective market context for a request: explicit session preferences win,
 * then a cached geo-IP lookup, then the configured default country. The
 * effective country/locale is stored back into the session.
 */
final class MarketContextResolver
{
    public const SESSION_KEY = 'market';

    public function __construct(
        private readonly CountryCatalog $countries,
        private readonly CountryLookup $geoIp,
        private readonly ExchangeRateProvider $rates,
    ) {
    }

    public function resolve(Request $request): MarketContext
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        $selected = $session?->get(self::SESSION_KEY);
        if (\is_array($selected)
            && \is_string($selected['country'] ?? null)
            && \is_string($selected['locale'] ?? null)
        ) {
            return $this->build($selected['country'], $selected['locale']);
        }

        $ip = $request->getClientIp() ?? '';
        $country = '' !== $ip ? $this->geoIp->resolve($ip) : null;
        $context = $this->build($country ?? '', null);
        $session?->set(self::SESSION_KEY, [
            'country' => $context->country,
            'locale' => $context->locale,
        ]);

        return $context;
    }

    /**
     * Resolves a context for an explicit country/locale without consulting or
     * mutating session preferences. Used by checkout, where the shipping
     * country drives pricing rather than the browsing market.
     */
    public function forCountry(string $country, ?string $locale): MarketContext
    {
        return $this->build($country, $locale);
    }

    /** Persists explicit preferences into the session and returns the new context. */
    public function applyPreferences(SessionInterface $session, string $country, string $locale): MarketContext
    {
        $context = $this->build($country, $locale);
        $session->set(self::SESSION_KEY, [
            'country' => $context->country,
            'locale' => $context->locale,
        ]);

        return $context;
    }

    private function build(string $country, ?string $locale): MarketContext
    {
        $resolved = $this->countries->resolve($country, $locale);
        try {
            $rate = $this->rates->rate(
                $resolved['currency'],
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            );
        } catch (ExchangeRateUnavailable) {
            throw new ApiProblem('exchange_rate_unavailable', 'No exchange rate is available for the resolved market currency.', 503);
        }

        return new MarketContext(
            $resolved['country'],
            $resolved['market'],
            $resolved['currency'],
            $resolved['locale'],
            $resolved['vat_basis_points'],
            $rate->rate,
        );
    }
}
