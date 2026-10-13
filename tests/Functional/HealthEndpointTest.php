<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Health\DependencyProbe;
use App\Health\ReadinessChecker;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthEndpointTest extends WebTestCase
{
    public function testLivenessDoesNotNeedDatabaseOrRedis(): void
    {
        $client = self::createClient();
        $postgres = new class implements DependencyProbe {
            public int $calls = 0;

            public function check(): void
            {
                ++$this->calls;
                throw new \LogicException('Liveness must not call PostgreSQL.');
            }
        };
        $redis = new class implements DependencyProbe {
            public int $calls = 0;

            public function check(): void
            {
                ++$this->calls;
                throw new \LogicException('Liveness must not call Redis.');
            }
        };
        self::getContainer()->set(ReadinessChecker::class, new ReadinessChecker($postgres, $redis));

        $client->request('GET', '/api/health/live');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertJsonStringEqualsJsonString('{"status":"ok"}', (string) $client->getResponse()->getContent());
        self::assertSame(0, $postgres->calls);
        self::assertSame(0, $redis->calls);
    }

    public function testReadinessSucceedsWhenBothDependenciesRespond(): void
    {
        $client = self::createClient();
        $available = $this->createStub(DependencyProbe::class);
        self::getContainer()->set(ReadinessChecker::class, new ReadinessChecker($available, $available));

        $client->request('GET', '/api/health/ready');

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(
            '{"status":"ok","checks":{"postgresql":"ok","redis":"ok"}}',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testReadinessReturns503AndHidesDependencyException(): void
    {
        $client = self::createClient();
        $available = $this->createStub(DependencyProbe::class);
        $unavailable = $this->createStub(DependencyProbe::class);
        $unavailable->method('check')->willThrowException(new \RuntimeException('postgresql://admin:super-secret@internal-db/private'));
        self::getContainer()->set(ReadinessChecker::class, new ReadinessChecker($unavailable, $available));

        $client->request('GET', '/api/health/ready');

        self::assertResponseStatusCodeSame(503);
        self::assertJsonStringEqualsJsonString(
            '{"status":"error","checks":{"postgresql":"error","redis":"ok"}}',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testUnknownApiRouteReturnsSafeJson(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/unknown-route');

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertJsonStringEqualsJsonString(
            '{"error":{"code":"not_found","message":"Not Found"}}',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testWrongHttpMethodPreservesAllowHeader(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/health/live');

        self::assertResponseStatusCodeSame(405);
        self::assertResponseHasHeader('allow');
        self::assertJsonStringEqualsJsonString(
            '{"error":{"code":"method_not_allowed","message":"Method Not Allowed"}}',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testVersionContainsOnlyPublicMetadata(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/version');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame(['name', 'environment', 'version'], array_keys($data));
        self::assertSame('test', $data['environment']);
    }
}
