<?php

declare(strict_types=1);

namespace App\Presentation\Http;

final class ApiProblem extends \RuntimeException
{
    /** @param array<string, list<string>> $fields */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
        public readonly array $fields = [],
    ) {
        parent::__construct($message);
    }
}
