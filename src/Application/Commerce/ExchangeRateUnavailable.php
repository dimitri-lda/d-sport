<?php

declare(strict_types=1);

namespace App\Application\Commerce;

/**
 * No usable quote could be produced for a currency: every external source
 * failed and no stored row exists to fall back to.
 */
final class ExchangeRateUnavailable extends \DomainException
{
}
