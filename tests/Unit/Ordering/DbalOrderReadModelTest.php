<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Infrastructure\Ordering\DbalOrderReadModel;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DbalOrderReadModel::class)]
final class DbalOrderReadModelTest extends TestCase
{
    public function testByNumberShapesOrderAddressAndItemSnapshots(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn([
            'id' => 9,
            'number' => 'SP-1234ABCD',
            'status' => 'pending_payment',
            'email' => 'buyer@example.test',
            'phone' => '+49123',
            'delivery_method' => 'express',
            'currency' => 'PLN',
            'market' => 'poland',
            'locale' => 'pl',
            'base_currency' => 'EUR',
            'exchange_rate' => '4.28000000',
            'vat_rate_basis_points' => 2300,
            'subtotal_cents' => 5264,
            'delivery_cents' => 1587,
            'total_cents' => 6851,
            'net_subtotal_cents' => 4280,
            'tax_cents' => 984,
            'delivery_net_cents' => 1290,
            'delivery_tax_cents' => 297,
            'created_at' => '2026-02-01 12:00:00+00',
            'user_id' => null,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'line1' => 'Main St 1',
            'line2' => null,
            'city' => 'Warsaw',
            'postal_code' => '00-001',
            'country' => 'PL',
        ]);
        $connection->method('fetchAllAssociative')->willReturn([[
            'id' => 31,
            'product_variant_id' => 77,
            'name' => 'Buty',
            'variant_name' => 'Rozmiar 42',
            'unit_price_cents' => 5264,
            'net_unit_price_cents' => 4280,
            'quantity' => 1,
            'line_total_cents' => 5264,
            'tax_cents' => 984,
        ]]);

        $order = (new DbalOrderReadModel($connection))->byNumber('SP-1234ABCD');

        self::assertIsArray($order);
        self::assertSame('SP-1234ABCD', $order['number']);
        self::assertSame('pending_payment', $order['status']);
        self::assertSame('express', $order['delivery_method']);
        self::assertSame(6851, $order['total_cents']);
        self::assertSame(4280, $order['net_subtotal_cents']);
        self::assertSame(984, $order['tax_cents']);
        self::assertSame('2026-02-01T12:00:00+00:00', $order['created_at']);
        self::assertSame('Warsaw', $order['address']['city']);
        self::assertNull($order['address']['line2']);
        self::assertCount(1, $order['items']);
        self::assertSame('Buty', $order['items'][0]['name']);
        self::assertSame(984, $order['items'][0]['tax_cents']);
    }

    public function testUnknownNumberReturnsNull(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(false);

        self::assertNull((new DbalOrderReadModel($connection))->byNumber('SP-00000000'));
    }
}
