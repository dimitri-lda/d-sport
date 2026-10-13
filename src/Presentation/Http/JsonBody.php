<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use Symfony\Component\HttpFoundation\Request;

/** Shared request JSON decoding: malformed or non-object bodies become a 400. */
final class JsonBody
{
    /** @return array<array-key, mixed> */
    public static function decode(Request $request): array
    {
        $decoded = json_decode((string) $request->getContent(), true);
        if (!\is_array($decoded)) {
            throw new ApiProblem('invalid_json', 'The request body must be a valid JSON object.', 400);
        }

        return $decoded;
    }

    /** Pulls a string field from a decoded body; null when absent/non-string. */
    public static function string(mixed $body, string $key): ?string
    {
        $value = \is_array($body) ? ($body[$key] ?? null) : null;

        return \is_string($value) ? $value : null;
    }

    /** Pulls a boolean field from a decoded body; null when absent/non-bool. */
    public static function bool(mixed $body, string $key): ?bool
    {
        $value = \is_array($body) ? ($body[$key] ?? null) : null;

        return \is_bool($value) ? $value : null;
    }
}
