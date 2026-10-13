<?php

declare(strict_types=1);

namespace App\Application\Commerce;

use App\Domain\Commerce\ExchangeRate;

/**
 * Quotes a currency against the external rate sources. Implementations try
 * each configured source in order and throw ExchangeRateUnavailable when
 * none of them answers.
 */
interface ExchangeRateFetcher
{
    /** @throws ExchangeRateUnavailable when every source fails */
    public function fetch(string $currency): ExchangeRate;
}
