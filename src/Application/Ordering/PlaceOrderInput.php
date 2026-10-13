<?php

declare(strict_types=1);

namespace App\Application\Ordering;

/** Flat checkout payload, matching the legacy order form fields. */
final class PlaceOrderInput
{
    public function __construct(
        public readonly string $email,
        public readonly string $phone,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $line1,
        public readonly ?string $line2,
        public readonly string $city,
        public readonly string $postalCode,
        public readonly string $country,
        public readonly string $deliveryMethod,
    ) {
    }
}
