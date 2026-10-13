<?php

declare(strict_types=1);

namespace App\Application\Ordering;

/** Legacy order number format: `SP-` plus 8 uppercase hex characters. */
final class RandomOrderNumberSource implements OrderNumberSource
{
    private const SUFFIX_BYTES = 4; // 8 hex chars

    public function next(): string
    {
        return 'SP-'.strtoupper(bin2hex(random_bytes(self::SUFFIX_BYTES)));
    }
}
