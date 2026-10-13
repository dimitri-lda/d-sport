<?php

declare(strict_types=1);

namespace App\Domain\Ordering;

enum OrderStatus: string
{
    case PendingPayment = 'pending_payment';
    case Paid = 'paid';
    case Processing = 'processing';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    /** @return list<OrderStatus> */
    public function allowedNextStatuses(): array
    {
        return match ($this) {
            self::PendingPayment => [self::Paid, self::Cancelled],
            self::Paid => [self::Processing, self::Refunded],
            self::Processing => [self::Shipped, self::Cancelled],
            self::Shipped => [self::Delivered],
            self::Delivered => [self::Refunded],
            self::Cancelled, self::Refunded => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return \in_array($next, $this->allowedNextStatuses(), true);
    }
}
