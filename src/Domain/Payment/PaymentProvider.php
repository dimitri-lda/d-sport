<?php

declare(strict_types=1);

namespace App\Domain\Payment;

enum PaymentProvider: string
{
    case ShopPay = 'shop_pay';
}
