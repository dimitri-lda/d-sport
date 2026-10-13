<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Ordering\OrderStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderStatusTest extends TestCase
{
    /** @return iterable<string, array{OrderStatus, OrderStatus, bool}> */
    public static function transitions(): iterable
    {
        yield 'pending -> paid' => [OrderStatus::PendingPayment, OrderStatus::Paid, true];
        yield 'pending -> cancelled' => [OrderStatus::PendingPayment, OrderStatus::Cancelled, true];
        yield 'paid -> processing' => [OrderStatus::Paid, OrderStatus::Processing, true];
        yield 'paid -> refunded' => [OrderStatus::Paid, OrderStatus::Refunded, true];
        yield 'processing -> shipped' => [OrderStatus::Processing, OrderStatus::Shipped, true];
        yield 'processing -> cancelled' => [OrderStatus::Processing, OrderStatus::Cancelled, true];
        yield 'shipped -> delivered' => [OrderStatus::Shipped, OrderStatus::Delivered, true];
        yield 'delivered -> refunded' => [OrderStatus::Delivered, OrderStatus::Refunded, true];
    }

    #[DataProvider('transitions')]
    public function testLegalTransitions(OrderStatus $from, OrderStatus $to, bool $expected): void
    {
        self::assertSame($expected, $from->canTransitionTo($to));
    }

    /** @return iterable<string, array{OrderStatus, OrderStatus}> */
    public static function illegalTransitions(): iterable
    {
        yield 'pending -> shipped' => [OrderStatus::PendingPayment, OrderStatus::Shipped];
        yield 'pending -> delivered' => [OrderStatus::PendingPayment, OrderStatus::Delivered];
        yield 'paid -> shipped' => [OrderStatus::Paid, OrderStatus::Shipped];
        yield 'paid -> cancelled' => [OrderStatus::Paid, OrderStatus::Cancelled];
        yield 'processing -> paid' => [OrderStatus::Processing, OrderStatus::Paid];
        yield 'shipped -> cancelled' => [OrderStatus::Shipped, OrderStatus::Cancelled];
        yield 'cancelled -> paid (terminal)' => [OrderStatus::Cancelled, OrderStatus::Paid];
        yield 'refunded -> paid (terminal)' => [OrderStatus::Refunded, OrderStatus::Paid];
        yield 'delivered -> cancelled' => [OrderStatus::Delivered, OrderStatus::Cancelled];
        yield 'paid -> paid (self)' => [OrderStatus::Paid, OrderStatus::Paid];
    }

    #[DataProvider('illegalTransitions')]
    public function testIllegalTransitionsAreRejected(OrderStatus $from, OrderStatus $to): void
    {
        self::assertFalse($from->canTransitionTo($to));
    }

    public function testAllowedNextStatusesMatchesCanTransitionToExhaustively(): void
    {
        foreach (OrderStatus::cases() as $from) {
            $expected = [];
            foreach (OrderStatus::cases() as $to) {
                if ($from->canTransitionTo($to)) {
                    $expected[] = $to;
                }
            }
            self::assertSame($expected, $from->allowedNextStatuses(), 'Mismatch for '.$from->value);
        }
    }
}
