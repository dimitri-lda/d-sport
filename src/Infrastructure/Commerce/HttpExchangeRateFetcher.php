<?php

declare(strict_types=1);

namespace App\Infrastructure\Commerce;

use App\Application\Commerce\ExchangeRateFetcher;
use App\Application\Commerce\ExchangeRateUnavailable;
use App\Domain\Commerce\ExchangeRate;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * External rate sources, chained per currency with graceful degradation:
 * BYN tries Myfin then the NBRB API, PLN tries Walutomat then the NBP API.
 * Provider decimals are extracted from raw response tokens — never through
 * float-typed JSON fields — so rates keep their exact decimal precision.
 * Nothing here logs request bodies or credentials.
 */
final class HttpExchangeRateFetcher implements ExchangeRateFetcher
{
    private const TIMEOUT = 4.0;
    private const NBRB_EUR_URL = 'https://api.nbrb.by/exrates/rates/EUR?parammode=2';
    private const NBP_EUR_URL = 'https://api.nbp.pl/api/exchangerates/rates/a/eur/?format=json';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $myfinEurBynUrl,
        private readonly string $walutomatEurPlnUrl,
        private readonly string $walutomatApiToken,
    ) {
    }

    public function fetch(string $currency): ExchangeRate
    {
        $currency = strtoupper($currency);
        $sources = match ($currency) {
            'BYN' => [
                ['myfin', $this->myfinByn(...)],
                ['nbrb', $this->nbrbByn(...)],
            ],
            'PLN' => [
                ['walutomat', $this->walutomatPln(...)],
                ['nbp', $this->nbpPln(...)],
            ],
            default => [],
        };

        foreach ($sources as [$source, $resolver]) {
            try {
                $rate = $resolver();

                return new ExchangeRate(
                    $currency,
                    $rate,
                    $source,
                    new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                );
            } catch (\Throwable) {
                // Any failure of one source moves on to the next.
            }
        }

        throw new ExchangeRateUnavailable('No exchange rate source could quote '.$currency.'.');
    }

    private function myfinByn(): string
    {
        if ('' === $this->myfinEurBynUrl) {
            throw new \RuntimeException('The Myfin EUR/BYN URL is not configured.');
        }
        $body = $this->get($this->myfinEurBynUrl);
        if (1 !== preg_match('/1\s*EUR.{0,120}?([0-9]+[,.][0-9]+)/isu', $body, $match)) {
            throw new \RuntimeException('The Myfin rate was not found.');
        }

        return self::decimal($match[1]);
    }

    private function nbrbByn(): string
    {
        $body = $this->get(self::NBRB_EUR_URL);
        if (1 !== preg_match('/"Cur_OfficialRate"\s*:\s*"?([0-9]+(?:[.,][0-9]+)?)"?/', $body, $match)) {
            throw new \RuntimeException('The NBRB rate was not found.');
        }

        return self::decimal($match[1]);
    }

    private function walutomatPln(): string
    {
        if ('' === $this->walutomatEurPlnUrl || '' === $this->walutomatApiToken) {
            throw new \RuntimeException('The Walutomat credentials are not configured.');
        }
        $body = $this->get($this->walutomatEurPlnUrl, ['auth_bearer' => $this->walutomatApiToken]);
        if (1 !== preg_match('/"(?:rate|price)"\s*:\s*"?([0-9]+(?:[.,][0-9]+)?)"?/', $body, $match)) {
            throw new \RuntimeException('The Walutomat rate was not found.');
        }

        return self::decimal($match[1]);
    }

    private function nbpPln(): string
    {
        $body = $this->get(self::NBP_EUR_URL);
        // Table A quotes foreign currencies in PLN; the first "mid" is
        // rates[0].mid — NBP lists EUR as the only entry for this endpoint.
        if (1 !== preg_match('/"mid"\s*:\s*"?([0-9]+(?:[.,][0-9]+)?)"?/', $body, $match)) {
            throw new \RuntimeException('The NBP rate was not found.');
        }

        return self::decimal($match[1]);
    }

    /** @param array<string, mixed> $options */
    private function get(string $url, array $options = []): string
    {
        $response = $this->httpClient->request('GET', $url, $options + ['timeout' => self::TIMEOUT]);

        return $response->getContent();
    }

    /**
     * Normalizes a raw provider token to a plain decimal string: comma to
     * dot, at most 8 fractional places, never through a float.
     */
    private static function decimal(string $token): string
    {
        $token = str_replace(',', '.', trim($token));
        if (1 !== preg_match('/^[0-9]+(\.[0-9]+)?$/', $token)) {
            throw new \RuntimeException('The provider returned an invalid decimal.');
        }
        if (str_contains($token, '.')) {
            [$integer, $fraction] = explode('.', $token, 2);
            $token = $integer.'.'.substr($fraction, 0, 8);
            $token = rtrim($token, '0');
            $token = rtrim($token, '.');
        }
        if ('' === $token || '0' === $token) {
            throw new \RuntimeException('The provider returned a zero rate.');
        }

        return $token;
    }
}
