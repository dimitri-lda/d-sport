<?php

declare(strict_types=1);

namespace App\Domain\Payment;

enum PaymentSessionStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Processing = 'processing';
    case Paid = 'paid';
    case Failed = 'failed';

    public function canTransitionTo(self $next): bool
    {
        return \in_array($next, match ($this) {
            self::Pending => [self::Ready, self::Processing, self::Failed],
            self::Ready => [self::Processing, self::Failed],
            self::Processing => [self::Paid, self::Failed],
            self::Paid, self::Failed => [],
        }, true);
    }
}
