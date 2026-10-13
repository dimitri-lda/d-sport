<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Catalog filter validation runs before the read model, so these cases do not
 * need a live database.
 */
final class CatalogValidationTest extends WebTestCase
{
    public function testPageZeroIsRejectedWithFields(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/products?page=0');

        self::assertResponseStatusCodeSame(422);
        self::assertJsonStringEqualsJsonString(
            '{"error":{"code":"validation_failed","message":"The given catalog filters are invalid.",'
            .'"fields":{"page":["The page must be a positive integer."]}}}',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testNonNumericPageIsRejected(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/products?page=abc');

        self::assertResponseStatusCodeSame(422);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame('validation_failed', $payload['error']['code'] ?? null);
        self::assertArrayHasKey('page', $payload['error']['fields'] ?? []);
    }

    public function testUnknownSortIsRejected(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/products?sort=cheapest');

        self::assertResponseStatusCodeSame(422);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertArrayHasKey('sort', $payload['error']['fields'] ?? []);
    }
}
