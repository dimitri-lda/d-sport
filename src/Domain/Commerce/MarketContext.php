<?php

declare(strict_types=1);

namespace App\Domain\Commerce;

final readonly class MarketContext
{
    public function __construct(
        public string $country,
        public string $market,
        public string $currency,
        public string $locale,
        public int $vatBasisPoints,
        public string $exchangeRate,
    ) {
    }
}
