<?php

declare(strict_types=1);

namespace App\Infrastructure\Commerce;

use App\Application\Commerce\ExchangeRateStore;
use App\Domain\Commerce\ExchangeRate;
use Doctrine\DBAL\Connection;

final class DbalExchangeRateStore implements ExchangeRateStore
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function find(string $currency): ?ExchangeRate
    {
        if ('EUR' === $currency) {
            return new ExchangeRate('EUR', '1.00000000', 'base', new \DateTimeImmutable());
        }

        $row = $this->connection->fetchAssociative(
            'SELECT rate, source, quoted_at FROM exchange_rates WHERE currency = :currency',
            ['currency' => $currency],
        );
        if (false === $row) {
            return null;
        }
        $rate = $row['rate'] ?? null;
        $source = $row['source'] ?? null;
        $quotedAt = $row['quoted_at'] ?? null;
        if (!\is_scalar($rate) || !\is_scalar($source) || !\is_scalar($quotedAt)) {
            return null;
        }

        return new ExchangeRate(
            $currency,
            (string) $rate,
            (string) $source,
            new \DateTimeImmutable((string) $quotedAt),
        );
    }

    public function save(ExchangeRate $rate): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO exchange_rates (currency, rate, source, quoted_at, updated_at)
                VALUES (:currency, :rate, :source, :quoted_at, CURRENT_TIMESTAMP)
                ON CONFLICT (currency) DO UPDATE
                SET rate = EXCLUDED.rate,
                    source = EXCLUDED.source,
                    quoted_at = EXCLUDED.quoted_at,
                    updated_at = CURRENT_TIMESTAMP
                SQL,
            [
                'currency' => $rate->currency,
                'rate' => $rate->rate,
                'source' => $rate->source,
                'quoted_at' => $rate->quotedAt->format('Y-m-d H:i:sP'),
            ],
        );
    }
}
