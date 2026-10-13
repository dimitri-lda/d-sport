<?php

declare(strict_types=1);

namespace App\Application\Ordering;

/** Produces candidate order numbers; injected for deterministic tests. */
interface OrderNumberSource
{
    public function next(): string;
}
