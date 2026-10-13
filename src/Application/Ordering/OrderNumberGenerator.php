<?php

declare(strict_types=1);

namespace App\Application\Ordering;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Order number issuance. The `orders_number_unique` index is the real
 * reservation point: callers hand out candidates from `next()` and rely on
 * `isNumberConflict()` to detect a flush-time collision worth retrying.
 */
final class OrderNumberGenerator
{
    public function __construct(private readonly OrderNumberSource $source)
    {
    }

    public function next(): string
    {
        return $this->source->next();
    }

    /** True only for a collision on the order number index — nothing else. */
    public function isNumberConflict(\Throwable $exception): bool
    {
        return $exception instanceof UniqueConstraintViolationException
            && str_contains($exception->getMessage(), 'orders_number_unique');
    }
}
