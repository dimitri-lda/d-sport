<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\VarExporter\LazyObjectInterface;
use Symfony\Contracts\Service\ResetInterface;

final class InfrastructureIntegrationTest extends WebTestCase
{
    protected function setUp(): void
    {
        if ('1' !== getenv('RUN_INFRASTRUCTURE_TESTS')) {
            self::markTestSkipped('Use make test to run against isolated PostgreSQL and Redis.');
        }
    }

    public function testReadinessAgainstActualDependencies(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/health/ready');

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(
            '{"status":"ok","checks":{"postgresql":"ok","redis":"ok"}}',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testApplicationCacheUsesRedisAndDeletesTemporaryKey(): void
    {
        $cache = self::getContainer()->get('cache.app');
        self::assertInstanceOf(RedisAdapter::class, $cache);
        $key = 'infrastructure_test_'.bin2hex(random_bytes(12));

        try {
            $item = $cache->getItem($key);
            $item->set('temporary-infrastructure-value')->expiresAfter(30);
            self::assertTrue($cache->save($item));
            $read = $cache->getItem($key);
            self::assertTrue($read->isHit());
            self::assertSame('temporary-infrastructure-value', $read->get());
        } finally {
            $cache->deleteItem($key);
        }

        self::assertFalse($cache->hasItem($key));
    }

    public function testBothDoctrineQueuesCanSendReceiveAndAcknowledge(): void
    {
        $container = self::getContainer();
        $database = $container->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $database);
        $databaseName = (string) $database->fetchOne('SELECT current_database()');
        self::assertStringContainsString('_test', $databaseName,
            'Infrastructure messages must stay in the isolated test database.');

        foreach (['async', 'failed'] as $queue) {
            $transport = $container->get('messenger.transport.'.$queue);
            self::assertInstanceOf(TransportInterface::class, $transport);
            // Mail sends now share the async queue — drain leftovers from
            // earlier tests so this transport check sees exactly its own
            // message.
            $database->executeStatement('DELETE FROM messenger_messages WHERE queue_name = ?', [$queue]);
            $message = new \stdClass();
            $message->token = bin2hex(random_bytes(12));

            try {
                $transport->send(new Envelope($message));
                $received = iterator_to_array($transport->get());
                self::assertCount(1, $received);
                $envelope = reset($received);
                self::assertInstanceOf(Envelope::class, $envelope);
                $decoded = $envelope->getMessage();
                self::assertInstanceOf(\stdClass::class, $decoded);
                self::assertSame($message->token, $decoded->token);
                $transport->ack($envelope);
                self::assertSame(0, (int) $database->fetchOne(
                    'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = ?',
                    [$queue],
                ));
            } finally {
                $database->executeStatement('DELETE FROM messenger_messages WHERE queue_name = ?', [$queue]);
            }
        }
    }

    public function testReusedKernelReconnectsClosedRedisForReadinessAndCache(): void
    {
        $kernel = self::bootKernel();
        $request = Request::create('/api/health/ready');
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        self::assertSame(200, $response->getStatusCode());

        $redis = self::getContainer()->get('app.redis_connection');
        self::assertInstanceOf(\Redis::class, $redis);
        self::assertInstanceOf(ResetInterface::class, $redis);
        self::assertInstanceOf(LazyObjectInterface::class, $redis);
        $previousConnection = $redis->initializeLazyObject();
        $cache = self::getContainer()->get('cache.app');
        self::assertInstanceOf(RedisAdapter::class, $cache);
        $key = 'worker_reconnect_test_'.bin2hex(random_bytes(12));

        try {
            $item = $cache->getItem($key)->set('survives-connection-reset')->expiresAfter(30);
            self::assertTrue($cache->save($item));
            $redis->close();
            self::assertFalse($redis->isConnected());

            // FrankenPHP keeps this same kernel and its shared services alive.
            // Its next handle() must reset the disconnected proxy automatically.
            $request = Request::create('/api/health/ready');
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);
            self::assertSame(200, $response->getStatusCode());
            self::assertNotSame($previousConnection, $redis->initializeLazyObject());
            self::assertSame('survives-connection-reset', $cache->getItem($key)->get());
        } finally {
            $cache->deleteItem($key);
        }
    }
}
