<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiProblemTest extends WebTestCase
{
    public function testApiProblemRendersFieldsEnvelopeWithStatus(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/test-probe/problem');

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertJsonStringEqualsJsonString(
            '{"error":{"code":"validation_failed","message":"Request validation failed.",'
            .'"fields":{"email":["This value is not a valid email address."],'
            .'"quantity":["This value should be greater than 0."]}}}',
            (string) $client->getResponse()->getContent(),
        );
    }
}
