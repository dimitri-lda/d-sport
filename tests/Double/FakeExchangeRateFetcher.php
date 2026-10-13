<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Application\Commerce\ExchangeRateFetcher;
use App\Application\Commerce\ExchangeRateUnavailable;
use App\Domain\Commerce\ExchangeRate;

/**
 * Test-env ExchangeRateFetcher (config/services_test.yaml). Never touches
 * the network: answers the two storefront currencies with the fixture
 * rates so the fetch-and-upsert path is exercised end to end. Prime
 * failures with fail() to test the stale fallback.
 */
final class FakeExchangeRateFetcher implements ExchangeRateFetcher
{
    private const RATES = [
        'PLN' => ['4.28000000', 'walutomat'],
        'BYN' => ['3.45000000', 'myfin'],
    ];

    /** @var array<string, true> */
    private static array $failing = [];

    public static function fail(string ...$currencies): void
    {
        foreach ($currencies as $currency) {
            self::$failing[strtoupper($currency)] = true;
        }
    }

    public static function reset(): void
    {
        self::$failing = [];
    }

    public function fetch(string $currency): ExchangeRate
    {
        $currency = strtoupper($currency);
        if (isset(self::$failing[$currency]) || !isset(self::RATES[$currency])) {
            throw new ExchangeRateUnavailable('The fake fetcher has no quote for '.$currency.'.');
        }
        [$rate, $source] = self::RATES[$currency];

        return new ExchangeRate(
            $currency,
            $rate,
            $source,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }
}
