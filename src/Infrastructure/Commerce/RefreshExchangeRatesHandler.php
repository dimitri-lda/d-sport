<?php

declare(strict_types=1);

namespace App\Infrastructure\Commerce;

use App\Application\Commerce\ExchangeRateRefresher;
use App\Application\Commerce\RefreshExchangeRates;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs the full refresh. A failed currency is logged generically and the
 * handler rethrows so Messenger retries — currencies that did refresh keep
 * their upserted quotes, so a redelivery only redoes the missing half.
 */
#[AsMessageHandler]
final class RefreshExchangeRatesHandler
{
    public function __construct(
        private readonly ExchangeRateRefresher $refresher,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(RefreshExchangeRates $message): void
    {
        $results = $this->refresher->refreshAll(
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
        foreach ($results as $currency => $result) {
            if (true !== ($result['ok'] ?? false)) {
                $this->logger->error('Exchange-rate refresh failed for a currency.', [
                    'currency' => $currency,
                    'error' => $result['error'] ?? 'unknown',
                ]);
                throw new \RuntimeException('Exchange-rate refresh failed for '.$currency.'.');
            }
        }
    }
}
