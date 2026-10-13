<?php

declare(strict_types=1);

namespace App\Application\Identity;

final readonly class AddressInput
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        public string $line1,
        public ?string $line2,
        public string $city,
        public string $postalCode,
        public string $country,
    ) {
    }
}
