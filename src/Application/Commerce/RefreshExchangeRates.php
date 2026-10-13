<?php

declare(strict_types=1);

namespace App\Application\Commerce;

/**
 * Scheduled trigger: refresh all external exchange-rate quotes. Handled
 * asynchronously; the handler is upsert-idempotent so redelivery is safe.
 */
final readonly class RefreshExchangeRates
{
}
