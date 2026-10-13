<?php

declare(strict_types=1);

namespace App\Application\Commerce;

use App\Domain\Commerce\ExchangeRate;

interface ExchangeRateStore
{
    public function find(string $currency): ?ExchangeRate;

    public function save(ExchangeRate $rate): void;
}
