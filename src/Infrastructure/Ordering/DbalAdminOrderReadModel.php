<?php

declare(strict_types=1);

namespace App\Infrastructure\Ordering;

use App\Domain\Ordering\OrderStatus;
use Doctrine\DBAL\Connection;

/**
 * Admin order listing read model. Parameterized SQL only, fixed allowlisted
 * ordering, snapshot columns only — never joins live products or payments.
 */
final class DbalAdminOrderReadModel
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly Connection $connection,
        private readonly DbalOrderReadModel $orders,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, page: int, per_page: int, total: int}
     */
    public function page(
        ?string $query,
        ?OrderStatus $status,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
        int $page,
        int $perPage = self::PER_PAGE,
    ): array {
        // Defense-in-depth clamp: callers should bound these already, but a
        // direct misuse must never produce a negative offset or giant page.
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 100));
        $where = [];
        $params = [];
        if (null !== $query && '' !== $query) {
            $where[] = '(o.number ILIKE :q OR o.email ILIKE :q)';
            $params['q'] = '%'.self::escapeLike($query).'%';
        }
        if (null !== $status) {
            $where[] = 'o.status = :status';
            $params['status'] = $status->value;
        }
        if (null !== $from) {
            $where[] = 'o.created_at >= :from';
            $params['from'] = $from->format('Y-m-d H:i:sP');
        }
        if (null !== $to) {
            // to is an inclusive UTC calendar day -> exclusive next midnight.
            $where[] = 'o.created_at < :to';
            $params['to'] = $to->modify('+1 day')->format('Y-m-d H:i:sP');
        }
        $clause = [] === $where ? '' : 'WHERE '.implode(' AND ', $where);

        $total = self::toInt($this->connection->fetchOne(
            'SELECT count(*) FROM orders o '.$clause,
            $params,
        ));

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT o.id, o.number, o.status, o.email, o.delivery_method,
                       o.total_cents, o.currency, o.created_at,
                       (SELECT count(*) FROM order_items i WHERE i.order_id = o.id) AS items_count
                FROM orders o
                {$clause}
                ORDER BY o.created_at DESC, o.id DESC
                LIMIT :limit OFFSET :offset
                SQL,
            $params + ['limit' => $perPage, 'offset' => ($page - 1) * $perPage],
            ['limit' => \Doctrine\DBAL\ParameterType::INTEGER, 'offset' => \Doctrine\DBAL\ParameterType::INTEGER],
        );

        $items = [];
        foreach ($rows as $row) {
            $row = self::stringKeyed($row);
            $items[] = [
                'id' => self::toInt($row['id'] ?? null),
                'number' => self::toString($row['number'] ?? null),
                'status' => self::toString($row['status'] ?? null),
                'email' => self::toString($row['email'] ?? null),
                'delivery_method' => self::toString($row['delivery_method'] ?? null),
                'total_cents' => self::toInt($row['total_cents'] ?? null),
                'currency' => self::toString($row['currency'] ?? null),
                'items_count' => self::toInt($row['items_count'] ?? null),
                'created_at' => self::toIso($row['created_at'] ?? null),
            ];
        }

        return ['items' => $items, 'page' => $page, 'per_page' => $perPage, 'total' => $total];
    }

    /** @return array<string, mixed>|null */
    public function detail(int $id): ?array
    {
        return $this->orders->byId($id);
    }

    /** @param array<array-key, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    private static function escapeLike(string $value): string
    {
        return strtr($value, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
    }

    private static function toInt(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }

        return \is_numeric($value) ? (int) $value : 0;
    }

    private static function toString(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    private static function toIso(mixed $value): ?string
    {
        if (!\is_string($value) || '' === $value) {
            return null;
        }
        $time = strtotime($value);

        return false === $time ? null : gmdate('c', $time);
    }
}
