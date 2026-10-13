<?php

declare(strict_types=1);

namespace App\Domain\Ordering;

enum DeliveryMethod: string
{
    case Standard = 'standard';
    case Express = 'express';
}
