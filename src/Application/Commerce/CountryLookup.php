<?php

declare(strict_types=1);

namespace App\Application\Commerce;

/** Best-effort IP-to-country lookup; returns null on any failure. */
interface CountryLookup
{
    public function resolve(string $ip): ?string;
}
