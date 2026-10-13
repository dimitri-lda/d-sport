<?php

declare(strict_types=1);

namespace App\Domain\Payment;

enum PaymentAttemptStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function canTransitionTo(self $next): bool
    {
        return \in_array($next, match ($this) {
            self::Pending => [self::Processing, self::Failed],
            self::Processing => [self::Completed, self::Failed],
            self::Completed, self::Failed => [],
        }, true);
    }
}
