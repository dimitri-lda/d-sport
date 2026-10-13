<?php

declare(strict_types=1);

namespace App\Application\Commerce;

use App\Domain\Commerce\ExchangeRate;

/**
 * Read-through exchange-rate access: fresh cache, then fresh stored quote,
 * then an external fetch with upsert, then a stale stored fallback.
 */
interface ExchangeRateProvider
{
    /** @throws ExchangeRateUnavailable when no quote exists at all */
    public function rate(string $currency, \DateTimeImmutable $now): ExchangeRate;

    /** Force-fetch and persist a new quote. @throws ExchangeRateUnavailable when all sources fail */
    public function refresh(string $currency, \DateTimeImmutable $now): ExchangeRate;
}
