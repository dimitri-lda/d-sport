<?php

declare(strict_types=1);

namespace App\Infrastructure\Ordering;

use Doctrine\DBAL\Connection;

/**
 * PostgreSQL read model for order display. Everything served from here is a
 * stored snapshot — never joined to live products, translations or prices.
 */
class DbalOrderReadModel
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return array<string, mixed>|null */
    public function byNumber(string $number): ?array
    {
        return $this->fetch('o.number = :number', ['number' => $number]);
    }

    /** @return array<string, mixed>|null */
    public function byId(int $id): ?array
    {
        return $this->fetch('o.id = :id', ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>|null
     */
    private function fetch(string $where, array $params): ?array
    {
        $row = $this->connection->fetchAssociative(
            <<<SQL
                SELECT o.id, o.number, o.status, o.email, o.phone, o.delivery_method,
                       o.currency, o.market, o.locale, o.base_currency, o.exchange_rate,
                       o.vat_rate_basis_points, o.subtotal_cents, o.delivery_cents, o.total_cents,
                       o.net_subtotal_cents, o.tax_cents, o.delivery_net_cents, o.delivery_tax_cents,
                       o.created_at, o.user_id,
                       a.first_name, a.last_name, a.line1, a.line2, a.city, a.postal_code, a.country
                FROM orders o
                LEFT JOIN order_addresses a ON a.order_id = o.id
                WHERE {$where}
                SQL,
            $params,
        );
        if (!\is_array($row)) {
            return null;
        }

        return $this->shape($row);
    }

    /** @return list<array<string, mixed>> */
    public function forUser(int $userId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT o.id, o.number, o.status, o.email, o.phone, o.delivery_method,
                       o.currency, o.market, o.locale, o.base_currency, o.exchange_rate,
                       o.vat_rate_basis_points, o.subtotal_cents, o.delivery_cents, o.total_cents,
                       o.net_subtotal_cents, o.tax_cents, o.delivery_net_cents, o.delivery_tax_cents,
                       o.created_at, o.user_id,
                       a.first_name, a.last_name, a.line1, a.line2, a.city, a.postal_code, a.country
                FROM orders o
                LEFT JOIN order_addresses a ON a.order_id = o.id
                WHERE o.user_id = :user
                ORDER BY o.created_at DESC, o.id DESC
                SQL,
            ['user' => $userId],
        );

        $orders = [];
        foreach ($rows as $row) {
            $orders[] = $this->shape(self::stringKeyed($row));
        }

        return $orders;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function shape(array $row): array
    {
        $order = [
            'id' => self::toInt($row['id'] ?? null),
            'number' => self::toString($row['number'] ?? null),
            'status' => self::toString($row['status'] ?? null),
            'email' => self::toString($row['email'] ?? null),
            'phone' => self::toString($row['phone'] ?? null),
            'delivery_method' => self::toString($row['delivery_method'] ?? null),
            'currency' => self::toString($row['currency'] ?? null),
            'market' => self::toString($row['market'] ?? null),
            'locale' => self::toString($row['locale'] ?? null),
            'base_currency' => self::toString($row['base_currency'] ?? null),
            'exchange_rate' => \is_string($row['exchange_rate'] ?? null) ? $row['exchange_rate'] : null,
            'vat_rate_basis_points' => null === $row['vat_rate_basis_points'] ? null : self::toInt($row['vat_rate_basis_points']),
            'subtotal_cents' => self::toInt($row['subtotal_cents'] ?? null),
            'delivery_cents' => self::toInt($row['delivery_cents'] ?? null),
            'total_cents' => self::toInt($row['total_cents'] ?? null),
            'net_subtotal_cents' => null === $row['net_subtotal_cents'] ? null : self::toInt($row['net_subtotal_cents']),
            'tax_cents' => null === $row['tax_cents'] ? null : self::toInt($row['tax_cents']),
            'delivery_net_cents' => null === $row['delivery_net_cents'] ? null : self::toInt($row['delivery_net_cents']),
            'delivery_tax_cents' => null === $row['delivery_tax_cents'] ? null : self::toInt($row['delivery_tax_cents']),
            'created_at' => self::toIso($row['created_at'] ?? null),
            'user_id' => null === $row['user_id'] ? null : self::toInt($row['user_id']),
            'address' => null === $row['first_name'] ? null : [
                'first_name' => self::toString($row['first_name']),
                'last_name' => self::toString($row['last_name'] ?? null),
                'line1' => self::toString($row['line1'] ?? null),
                'line2' => \is_string($row['line2'] ?? null) ? $row['line2'] : null,
                'city' => self::toString($row['city'] ?? null),
                'postal_code' => self::toString($row['postal_code'] ?? null),
                'country' => self::toString($row['country'] ?? null),
            ],
            'items' => $this->items(self::toInt($row['id'] ?? null)),
        ];

        return $order;
    }

    /** @return list<array<string, mixed>> */
    private function items(int $orderId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT id, product_variant_id, name, variant_name, unit_price_cents,
                       net_unit_price_cents, quantity, line_total_cents, tax_cents
                FROM order_items
                WHERE order_id = :order
                ORDER BY id ASC
                SQL,
            ['order' => $orderId],
        );

        $items = [];
        foreach ($rows as $row) {
            $row = self::stringKeyed($row);
            $items[] = [
                'id' => self::toInt($row['id'] ?? null),
                'product_variant_id' => null === $row['product_variant_id'] ? null : self::toInt($row['product_variant_id']),
                'name' => self::toString($row['name'] ?? null),
                'variant_name' => self::toString($row['variant_name'] ?? null),
                'unit_price_cents' => self::toInt($row['unit_price_cents'] ?? null),
                'net_unit_price_cents' => null === $row['net_unit_price_cents'] ? null : self::toInt($row['net_unit_price_cents']),
                'quantity' => self::toInt($row['quantity'] ?? null),
                'line_total_cents' => self::toInt($row['line_total_cents'] ?? null),
                'tax_cents' => null === $row['tax_cents'] ? null : self::toInt($row['tax_cents']),
            ];
        }

        return $items;
    }

    /**
     * @param array<array-key, mixed> $row
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
