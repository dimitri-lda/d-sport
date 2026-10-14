<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\Catalog\Entity\Category;
use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Identity\Entity\User;
use App\Domain\Ordering\DeliveryMethod;
use App\Domain\Ordering\Entity\Order;
use App\Domain\Ordering\Entity\OrderAddress;
use App\Domain\Ordering\OrderStatus;
use App\Tests\Double\RecordingCatalogCacheInvalidator;
use App\Tests\Support\FlushesRedis;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Admin order list/detail/transition flows against the per-run isolated
 * test database. Requires Docker postgres+redis (see scripts/backend-test.sh).
 */
final class AdminOrdersFlowTest extends WebTestCase
{
    use FlushesRedis;

    private const PASSWORD = 'Sport-E!2026';

    protected function setUp(): void
    {
        if ('1' !== getenv('RUN_INFRASTRUCTURE_TESTS')) {
            self::markTestSkipped('Use make test to run against isolated PostgreSQL and Redis.');
        }
        // All tests share the 127.0.0.1 client IP and the Redis-backed
        // limiter storage — flush it so windows stay per-test.
        self::flushRedis();
    }

    private static function csrf(KernelBrowser $client): string
    {
        $client->request('GET', '/api/v1/context');
        $token = self::payload($client)['data']['csrf_token'] ?? null;
        self::assertIsString($token);

        return $token;
    }

    /** @param array<string, mixed> $body */
    private static function jsonRequest(
        KernelBrowser $client,
        string $method,
        string $uri,
        array $body = [],
        ?string $csrf = null,
    ): void {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if (null !== $csrf) {
            $server['HTTP_X_CSRF_TOKEN'] = $csrf;
        }
        $client->request($method, $uri, [], [], $server, (string) json_encode($body));
    }

    /** @return array<string, mixed> */
    private static function payload(KernelBrowser $client): array
    {
        $decoded = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private static function em(): EntityManagerInterface
    {
        // ORM 3's wrapInTransaction closes the manager on any exception —
        // including expected 422 ApiProblems — so a later reuse must reset it.
        $registry = self::getContainer()->get('doctrine');
        \assert($registry instanceof ManagerRegistry);
        $manager = $registry->getManager();
        if (!$manager instanceof EntityManagerInterface || !$manager->isOpen()) {
            $manager = $registry->resetManager();
        }
        \assert($manager instanceof EntityManagerInterface);
        $manager->clear();

        return $manager;
    }

    private static function connection(): Connection
    {
        return self::em()->getConnection();
    }

    private static function stock(int $variantId): int
    {
        return (int) self::connection()->fetchOne(
            'SELECT stock FROM product_variants WHERE id = :id',
            ['id' => $variantId],
        );
    }

    private static function variant(int $stock): int
    {
        $em = self::em();
        $tag = bin2hex(random_bytes(4));
        $category = new Category('cat-'.$tag);
        $product = new Product($category, 'product-'.$tag, 1000);
        $product->addTranslation('en', 'Admin Product '.$tag, 'Description');
        $variant = $product->addVariant('SKU-'.$tag, null, $stock);
        $variant->addTranslation('en', 'One size');
        $em->persist($category);
        $em->persist($product);
        $em->flush();
        $id = $variant->getId();
        self::assertIsInt($id);

        return $id;
    }

    /**
     * Creates an order row with snapshot data and reserved inventory,
     * optionally already transitioned away from pending_payment.
     *
     * @return array{order_id: int, variant_id: int, number: string}
     */
    private static function reservedOrder(int $stock, int $quantity, string $email = 'buyer@example.test'): array
    {
        $variantId = self::variant($stock);
        $em = self::em();
        $variant = $em->find(ProductVariant::class, $variantId);
        self::assertInstanceOf(ProductVariant::class, $variant);
        $variant->reserve($quantity);

        $number = 'SP-'.strtoupper(bin2hex(random_bytes(4)));
        $order = new Order(
            $number, $email, '+49123', DeliveryMethod::Standard,
            $quantity * 1190, 702, 'EUR', 'eu_global', 'en',
        );
        $order->setTaxBreakdown($quantity * 1000, $quantity * 190, 590, 112);
        $order->addItem($variant, 'Admin Product', 'One size', 1190, $quantity, 1000, $quantity * 190);
        $order->setAddress(new OrderAddress($order, 'Jane', 'Doe', 'Main 1', null, 'Berlin', '10115', 'DE'));
        $order->reserveInventory(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $em->persist($order);
        $em->flush();
        $id = $order->getId();
        self::assertIsInt($id);

        return ['order_id' => $id, 'variant_id' => $variantId, 'number' => $number];
    }

    private static function admin(KernelBrowser $client): void
    {
        $em = self::em();
        $email = uniqid('admin', true).'@example.test';
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = new User($email, 'pending', 'Admin', ['ROLE_ADMIN']);
        $admin->changePassword($hasher->hashPassword($admin, self::PASSWORD));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $admin->verifyEmail($now);
        $admin->acceptTerms($now);
        $em->persist($admin);
        $em->flush();

        self::jsonRequest($client, 'POST', '/api/v1/auth/login', [
            'email' => $email,
            'password' => self::PASSWORD,
        ], self::csrf($client));
        self::assertResponseStatusCodeSame(200);
    }

    private static function register(KernelBrowser $client, string $email): void
    {
        self::jsonRequest($client, 'POST', '/api/v1/auth/register', [
            'display_name' => 'Flow User',
            'email' => $email,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'accepted_terms' => true,
        ], self::csrf($client));
        self::assertResponseStatusCodeSame(201);
    }

    private static function patchStatus(KernelBrowser $client, int $orderId, string $status): void
    {
        self::jsonRequest($client, 'PATCH', '/api/v1/admin/orders/'.$orderId.'/status', [
            'status' => $status,
        ], self::csrf($client));
    }

    public function testGuestAndCustomerAreRejectedFromAdminOrders(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/admin/orders');
        self::assertResponseStatusCodeSame(401);

        self::register($client, uniqid('plain', true).'@example.test');
        $client->request('GET', '/api/v1/admin/orders');
        self::assertResponseStatusCodeSame(403);
    }

    public function testListFiltersByQueryStatusAndDate(): void
    {
        $client = self::createClient();
        self::admin($client);
        $target = self::reservedOrder(5, 1, 'findme@example.test');
        self::reservedOrder(5, 1, 'other@example.test');

        // Number search, case-insensitive.
        $client->request('GET', '/api/v1/admin/orders?q='.strtolower($target['number']));
        self::assertResponseStatusCodeSame(200);
        $payload = self::payload($client);
        self::assertSame(1, $payload['meta']['total']);
        self::assertSame($target['number'], $payload['data'][0]['number']);
        self::assertContains('pending_payment', $payload['statuses']);

        // Email search.
        $client->request('GET', '/api/v1/admin/orders?q=FINDME');
        self::assertSame(1, self::payload($client)['meta']['total']);

        // Status filter excludes the pending rows.
        $client->request('GET', '/api/v1/admin/orders?status=shipped');
        self::assertSame(0, self::payload($client)['meta']['total']);

        // Date window: today includes the rows, a past window does not.
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
        $client->request('GET', '/api/v1/admin/orders?from='.$today.'&to='.$today);
        self::assertGreaterThanOrEqual(2, self::payload($client)['meta']['total']);
        $client->request('GET', '/api/v1/admin/orders?from=2000-01-01&to=2000-01-02');
        self::assertSame(0, self::payload($client)['meta']['total']);

        // Invalid inputs are field errors, never 500s.
        $client->request('GET', '/api/v1/admin/orders?status=bogus');
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertArrayHasKey('status', self::payload($client)['error']['fields']);
        $client->request('GET', '/api/v1/admin/orders?from='.$today.'&to=2000-01-01');
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $client->request('GET', '/api/v1/admin/orders?page=0');
        self::assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testDetailExposesSnapshotsAndNextStatusesWithoutPaymentData(): void
    {
        $client = self::createClient();
        self::admin($client);
        $order = self::reservedOrder(5, 2);

        $client->request('GET', '/api/v1/admin/orders/'.$order['order_id']);
        self::assertResponseStatusCodeSame(200);
        $data = self::payload($client)['data'];

        self::assertSame($order['number'], $data['order']['number']);
        self::assertSame('pending_payment', $data['order']['status']);
        self::assertSame('Admin Product', $data['order']['items'][0]['name']);
        self::assertSame('Berlin', $data['order']['address']['city']);
        self::assertSame(2380, $data['order']['subtotal_cents']);
        self::assertSame(3082, $data['order']['total_cents']);
        self::assertSame(
            [OrderStatus::Paid->value, OrderStatus::Cancelled->value],
            $data['next_statuses'],
        );

        // Snapshot data only: no encrypted/payment internals ever leave the
        // API. Keys are checked recursively — values legitimately contain
        // words like "pending_payment".
        $keys = [];
        $collect = static function (array $node) use (&$collect, &$keys): void {
            foreach ($node as $key => $value) {
                if (\is_string($key)) {
                    $keys[] = $key;
                }
                if (\is_array($value)) {
                    $collect($value);
                }
            }
        };
        $collect($data);
        foreach ($keys as $key) {
            foreach (['payment', 'cipher', 'token', 'secret'] as $needle) {
                self::assertStringNotContainsString($needle, $key);
            }
        }

        $client->request('GET', '/api/v1/admin/orders/424242');
        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertSame('not_found', self::payload($client)['error']['code']);
    }

    public function testEveryLegalTransitionAndIllegalOnesAreRejected(): void
    {
        $client = self::createClient();
        self::admin($client);
        $order = self::reservedOrder(9, 1);

        foreach ([
            OrderStatus::Paid->value => [OrderStatus::Processing->value, OrderStatus::Refunded->value],
            OrderStatus::Processing->value => [OrderStatus::Shipped->value, OrderStatus::Cancelled->value],
            OrderStatus::Shipped->value => [OrderStatus::Delivered->value, OrderStatus::Refunded->value],
            OrderStatus::Delivered->value => [OrderStatus::Refunded->value],
            OrderStatus::Refunded->value => [],
        ] as $target => $expectedNext) {
            self::patchStatus($client, $order['order_id'], $target);
            self::assertResponseStatusCodeSame(200, 'Transition to '.$target);
            $data = self::payload($client)['data'];
            self::assertSame($target, $data['status']);
            self::assertSame($expectedNext, $data['next_statuses']);
        }

        // Terminal state: every further transition is a 422 field error.
        self::patchStatus($client, $order['order_id'], OrderStatus::Paid->value);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $error = self::payload($client)['error'];
        self::assertSame('invalid_status_transition', $error['code']);
        self::assertArrayHasKey('status', $error['fields']);

        self::patchStatus($client, 424242, OrderStatus::Paid->value);
        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertSame('not_found', self::payload($client)['error']['code']);
    }

    public function testStockReleaseFollowsWhetherTheGoodsLeftTheWarehouse(): void
    {
        $client = self::createClient();
        self::admin($client);

        // Pending order holding 2 of 5 units: cancel releases them.
        $cancelled = self::reservedOrder(5, 2);
        self::assertSame(3, self::stock($cancelled['variant_id']));
        self::patchStatus($client, $cancelled['order_id'], OrderStatus::Cancelled->value);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(5, self::stock($cancelled['variant_id']));

        // Repeating the transition is impossible (terminal) and cannot
        // restore stock a second time.
        self::patchStatus($client, $cancelled['order_id'], OrderStatus::Cancelled->value);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame(5, self::stock($cancelled['variant_id']));

        // Paid -> processing -> cancelled also releases.
        $lateCancelled = self::reservedOrder(6, 2);
        self::patchStatus($client, $lateCancelled['order_id'], OrderStatus::Paid->value);
        self::patchStatus($client, $lateCancelled['order_id'], OrderStatus::Processing->value);
        self::patchStatus($client, $lateCancelled['order_id'], OrderStatus::Cancelled->value);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(6, self::stock($lateCancelled['variant_id']));

        // Paid -> refunded releases: the goods never left the warehouse.
        $earlyRefund = self::reservedOrder(7, 2);
        self::patchStatus($client, $earlyRefund['order_id'], OrderStatus::Paid->value);
        self::patchStatus($client, $earlyRefund['order_id'], OrderStatus::Refunded->value);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(7, self::stock($earlyRefund['variant_id']));

        // Shipped -> refunded keeps the reservation: the goods are with
        // the carrier.
        $shipped = self::reservedOrder(8, 2);
        self::patchStatus($client, $shipped['order_id'], OrderStatus::Paid->value);
        self::patchStatus($client, $shipped['order_id'], OrderStatus::Processing->value);
        self::patchStatus($client, $shipped['order_id'], OrderStatus::Shipped->value);
        self::patchStatus($client, $shipped['order_id'], OrderStatus::Refunded->value);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(6, self::stock($shipped['variant_id']));

        // Delivered -> refunded keeps it too.
        $delivered = self::reservedOrder(9, 2);
        self::patchStatus($client, $delivered['order_id'], OrderStatus::Paid->value);
        self::patchStatus($client, $delivered['order_id'], OrderStatus::Processing->value);
        self::patchStatus($client, $delivered['order_id'], OrderStatus::Shipped->value);
        self::patchStatus($client, $delivered['order_id'], OrderStatus::Delivered->value);
        self::patchStatus($client, $delivered['order_id'], OrderStatus::Refunded->value);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(7, self::stock($delivered['variant_id']));
    }

    public function testOnlyCommittedStockReleasesInvalidateTheCatalogCache(): void
    {
        $client = self::createClient();
        self::admin($client);
        RecordingCatalogCacheInvalidator::reset();

        // A committed cancel releases stock and rotates list/card once.
        $cancelled = self::reservedOrder(5, 2);
        self::patchStatus($client, $cancelled['order_id'], OrderStatus::Cancelled->value);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['productsChanged'], RecordingCatalogCacheInvalidator::calls());

        // An illegal transition rolls back: no invalidation may escape.
        self::patchStatus($client, $cancelled['order_id'], OrderStatus::Paid->value);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame(['productsChanged'], RecordingCatalogCacheInvalidator::calls());

        // A refund from paid releases stock too: one more invalidation.
        $refunded = self::reservedOrder(5, 1);
        self::patchStatus($client, $refunded['order_id'], OrderStatus::Paid->value);
        self::assertResponseStatusCodeSame(200);
        self::patchStatus($client, $refunded['order_id'], OrderStatus::Refunded->value);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            ['productsChanged', 'productsChanged'],
            RecordingCatalogCacheInvalidator::calls(),
        );

        // A refund after shipping restores no stock: no invalidation.
        $shipped = self::reservedOrder(5, 1);
        foreach ([OrderStatus::Paid, OrderStatus::Processing, OrderStatus::Shipped] as $step) {
            self::patchStatus($client, $shipped['order_id'], $step->value);
            self::assertResponseStatusCodeSame(200);
        }
        self::patchStatus($client, $shipped['order_id'], OrderStatus::Refunded->value);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            ['productsChanged', 'productsChanged'],
            RecordingCatalogCacheInvalidator::calls(),
            'Only committed stock releases may invalidate the catalog cache.',
        );
    }
}
