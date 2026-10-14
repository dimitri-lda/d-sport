<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ApiProblem extends \RuntimeException implements HttpExceptionInterface
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

    /**
     * Implementing HttpExceptionInterface keeps Symfony's error listener from
     * logging every ordinary 4xx API problem at CRITICAL level.
     */
    public function getStatusCode(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return [];
    }
}
