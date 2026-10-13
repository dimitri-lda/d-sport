<?php

declare(strict_types=1);

namespace App\Application\Commerce;

/**
 * Refreshes every storefront fiat currency independently and reports a
 * safe per-currency result (rate + source, or a generic error class —
 * never a URL, token, or response body).
 */
final class ExchangeRateRefresher
{
    private const CURRENCIES = ['PLN', 'BYN'];

    public function __construct(private readonly ExchangeRateProvider $provider)
    {
    }

    /** @return array<string, array{ok: bool, rate?: string, source?: string, error?: string}> */
    public function refreshAll(\DateTimeImmutable $now): array
    {
        $results = [];
        foreach (self::CURRENCIES as $currency) {
            try {
                $rate = $this->provider->refresh($currency, $now);
                $results[$currency] = ['ok' => true, 'rate' => $rate->rate, 'source' => $rate->source];
            } catch (\Throwable $exception) {
                $results[$currency] = ['ok' => false, 'error' => $exception::class];
            }
        }

        return $results;
    }
}
