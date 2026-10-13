<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Ordering\InventoryRelease;
use App\Domain\Catalog\Entity\Category;
use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Identity\Entity\User;
use App\Domain\Ordering\DeliveryMethod;
use App\Domain\Ordering\Entity\Order;
use App\Tests\Double\QueueOrderNumberSource;
use App\Tests\Double\RecordingCatalogCacheInvalidator;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End-to-end cart/checkout/order flows against the per-run isolated test
 * database. Requires Docker postgres+redis (see scripts/backend-test.sh).
 */
final class CartCheckoutFlowTest extends WebTestCase
{
    private const PASSWORD = 'Sport-E!2026';

    protected function setUp(): void
    {
        if ('1' !== getenv('RUN_INFRASTRUCTURE_TESTS')) {
            self::markTestSkipped('Use make test to run against isolated PostgreSQL and Redis.');
        }
    }

    private static function csrf(KernelBrowser $client): string
    {
        $client->request('GET', '/api/v1/context');
        $payload = self::payload($client);
        $token = $payload['data']['csrf_token'] ?? null;
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

    /**
     * A second browser against the same kernel: this WebTestCase allows one
     * createClient() per test, so additional independent cookie jars (guest
     * sessions) come from plain KernelBrowser instances.
     */
    private static function secondClient(): KernelBrowser
    {
        self::assertNotNull(self::$kernel);

        return new KernelBrowser(self::$kernel);
    }

    private static function guestCarts(): int
    {
        return (int) self::connection()->fetchOne(
            'SELECT count(*) FROM carts WHERE user_id IS NULL AND guest_token IS NOT NULL',
        );
    }

    private static function connection(): Connection
    {
        return self::em()->getConnection();
    }

    /** Creates a sellable variant fixture; returns its id. */
    private static function variant(int $stock, int $netCents = 1000): int
    {
        $em = self::em();
        $tag = bin2hex(random_bytes(4));
        $category = new Category('cat-'.$tag);
        $product = new Product($category, 'product-'.$tag, $netCents);
        $product->addTranslation('en', 'Flow Product '.$tag, 'Description');
        $product->addImage('/img/'.$tag.'.png', null, 0);
        $variant = $product->addVariant('SKU-'.$tag, null, $stock);
        $variant->addTranslation('en', 'One size');
        $em->persist($category);
        $em->persist($product);
        $em->flush();
        $id = $variant->getId();
        self::assertIsInt($id);

        return $id;
    }

    /** @return array<string, mixed> */
    private static function orderBody(array $overrides = []): array
    {
        return $overrides + [
            'email' => 'buyer@example.test',
            'phone' => '+49123456789',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'line1' => 'Main Street 1',
            'line2' => null,
            'city' => 'Berlin',
            'postal_code' => '10115',
            'country' => 'DE',
            'delivery_method' => 'standard',
        ];
    }

    private static function addToCart(KernelBrowser $client, int $variantId, int $quantity): void
    {
        self::jsonRequest($client, 'POST', '/api/v1/cart/items', [
            'variant_id' => $variantId,
            'quantity' => $quantity,
        ], self::csrf($client));
        self::assertResponseStatusCodeSame(201);
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

    private static function login(KernelBrowser $client, string $email): void
    {
        self::jsonRequest($client, 'POST', '/api/v1/auth/login', [
            'email' => $email,
            'password' => self::PASSWORD,
        ], self::csrf($client));
        self::assertResponseStatusCodeSame(200);
    }

    private static function logout(KernelBrowser $client): void
    {
        self::jsonRequest($client, 'POST', '/api/v1/auth/logout', [], self::csrf($client));
        self::assertResponseStatusCodeSame(204);
    }

    private static function cartCount(KernelBrowser $client): int
    {
        $client->request('GET', '/api/v1/cart');
        self::assertSame(200, $client->getResponse()->getStatusCode());

        return (int) self::payload($client)['data']['count'];
    }

    public function testGuestCartCrudRequiresCsrfAndCapsAtStock(): void
    {
        $client = self::createClient();
        $variantId = self::variant(5);

        // Missing CSRF -> 419 through the shared subscriber.
        self::jsonRequest($client, 'POST', '/api/v1/cart/items', [
            'variant_id' => $variantId,
            'quantity' => 1,
        ]);
        self::assertResponseStatusCodeSame(419);

        // Add twice: quantities sum, capped at stock.
        self::addToCart($client, $variantId, 2);
        self::addToCart($client, $variantId, 9);
        $client->request('GET', '/api/v1/cart');
        $cart = self::payload($client)['data'];
        self::assertSame(5, $cart['count']);
        $item = $cart['items'][0];
        self::assertSame(5, $item['quantity']);
        self::assertSame(1190, $item['unit_price_cents']); // 1000 net, 19% DE VAT
        self::assertSame('Flow Product', substr($item['product']['name'], 0, 12));

        // Unknown variant -> 404.
        self::jsonRequest($client, 'POST', '/api/v1/cart/items', [
            'variant_id' => 999999,
            'quantity' => 1,
        ], self::csrf($client));
        self::assertResponseStatusCodeSame(404);
        self::assertSame('product_variant_not_found', self::payload($client)['error']['code']);

        // Quantity update, including removal via 0.
        self::jsonRequest($client, 'PATCH', '/api/v1/cart/items/'.$item['id'], [
            'quantity' => 2,
        ], self::csrf($client));
        self::assertResponseStatusCodeSame(200);
        self::jsonRequest($client, 'PATCH', '/api/v1/cart/items/424242', [
            'quantity' => 1,
        ], self::csrf($client));
        self::assertResponseStatusCodeSame(404);
        self::assertSame('cart_item_not_found', self::payload($client)['error']['code']);

        $client->request('GET', '/api/v1/cart');
        $itemId = self::payload($client)['data']['items'][0]['id'];
        self::jsonRequest($client, 'DELETE', '/api/v1/cart/items/'.$itemId, [], self::csrf($client));
        self::assertResponseStatusCodeSame(200);
        self::assertSame(0, self::payload($client)['data']['count']);
    }

    public function testGuestCartsAreIsolated(): void
    {
        $clientA = self::createClient();
        $variantId = self::variant(5);
        self::addToCart($clientA, $variantId, 2);
        $clientA->request('GET', '/api/v1/cart');
        $itemId = self::payload($clientA)['data']['items'][0]['id'];

        $clientB = self::secondClient();
        self::assertSame(0, self::cartCount($clientB), 'A second guest must get an independent cart.');
        self::jsonRequest($clientB, 'PATCH', '/api/v1/cart/items/'.$itemId, [
            'quantity' => 1,
        ], self::csrf($clientB));
        // Assert against clientB's response: status helpers only see test.client.
        self::assertSame(
            404,
            $clientB->getResponse()->getStatusCode(),
            'Foreign cart lines must look absent, never forbidden.',
        );
    }

    public function testGuestCartMergesIntoUserOnLoginWithOverwrite(): void
    {
        $client = self::createClient();
        $variantId = self::variant(10);
        $email = uniqid('merge', true).'@example.test';
        self::register($client, $email);

        // Authenticated line: quantity 1.
        self::addToCart($client, $variantId, 1);
        self::logout($client);

        // Fresh guest session: same variant, quantity 3.
        $baseline = self::guestCarts();
        self::addToCart($client, $variantId, 3);
        self::assertSame($baseline + 1, self::guestCarts());

        self::login($client, $email);

        $client->request('GET', '/api/v1/cart');
        $cart = self::payload($client)['data'];
        self::assertCount(1, $cart['items']);
        self::assertSame(3, $cart['items'][0]['quantity'], 'Merge overwrites the user line; it must not sum to 4.');
        self::assertSame($baseline, self::guestCarts(), 'The guest cart row must be deleted after merge.');
    }

    public function testGuestCartMergesIntoUserOnRegister(): void
    {
        $client = self::createClient();
        $variantId = self::variant(10);
        $baseline = self::guestCarts();
        self::addToCart($client, $variantId, 2);

        self::register($client, uniqid('regmerge', true).'@example.test');

        $client->request('GET', '/api/v1/cart');
        self::assertSame(2, self::payload($client)['data']['count']);
        self::assertSame($baseline, self::guestCarts());
    }

    public function testCheckoutBootstrapExposesCartUserAddressAndCountries(): void
    {
        $client = self::createClient();
        $variantId = self::variant(3);
        self::addToCart($client, $variantId, 1);

        $client->request('GET', '/api/v1/checkout');
        self::assertResponseStatusCodeSame(200);
        $data = self::payload($client)['data'];
        self::assertSame(1, $data['cart']['count']);
        self::assertNull($data['user']);
        self::assertNull($data['address']);
        self::assertNotEmpty($data['countries']);
    }

    public function testGuestOrderPlacementAndOwnershipViaLastOrder(): void
    {
        $client = self::createClient();
        $variantId = self::variant(4);
        self::addToCart($client, $variantId, 2);

        self::jsonRequest($client, 'POST', '/api/v1/orders', self::orderBody(), self::csrf($client));
        self::assertResponseStatusCodeSame(201);
        $placed = self::payload($client)['data'];
        self::assertMatchesRegularExpression('/^SP-[A-F0-9]{8}$/', $placed['number']);

        // Stock reserved, cart cleared in the same commit.
        $stock = (int) self::connection()->fetchOne(
            'SELECT stock FROM product_variants WHERE id = :id',
            ['id' => $variantId],
        );
        self::assertSame(2, $stock);
        self::assertSame(0, self::cartCount($client));

        // Guest reads their own order through the last_order session marker.
        $client->request('GET', '/api/v1/orders/'.$placed['number']);
        self::assertResponseStatusCodeSame(200);
        $order = self::payload($client)['data'];
        self::assertSame('pending_payment', $order['status']);
        self::assertSame('buyer@example.test', $order['email']);
        self::assertSame('standard', $order['delivery_method']);
        self::assertSame('EUR', $order['currency']);
        self::assertSame('Berlin', $order['address']['city']);
        self::assertCount(1, $order['items']);
        self::assertSame(2380, $order['items'][0]['line_total_cents']);
        self::assertSame(380, $order['items'][0]['tax_cents']); // line tax only
        self::assertSame(2000, $order['net_subtotal_cents']);
        // tax_cents is item tax only; delivery tax is a separate snapshot.
        self::assertSame(380, $order['tax_cents']);
        self::assertSame(112, $order['delivery_tax_cents']);
        self::assertSame(590, $order['delivery_net_cents']);
        self::assertSame(702, $order['delivery_cents']);
        self::assertSame(3082, $order['total_cents']);
        self::assertNotNull($order['created_at']);

        // A second guest cannot read it.
        $stranger = self::secondClient();
        $stranger->request('GET', '/api/v1/orders/'.$placed['number']);
        self::assertSame(404, $stranger->getResponse()->getStatusCode());
        self::assertSame('not_found', self::payload($stranger)['error']['code']);
    }

    public function testOrderValidationReturnsFieldErrors(): void
    {
        $client = self::createClient();
        $variantId = self::variant(4);
        self::addToCart($client, $variantId, 1);

        self::jsonRequest($client, 'POST', '/api/v1/orders', self::orderBody([
            'email' => 'not-an-email',
            'country' => 'US',
            'delivery_method' => 'drone',
        ]), self::csrf($client));
        self::assertResponseStatusCodeSame(422);
        $fields = self::payload($client)['error']['fields'];
        self::assertArrayHasKey('email', $fields);
        self::assertArrayHasKey('country', $fields);
        self::assertArrayHasKey('delivery_method', $fields);
    }

    public function testEmptyCartCannotCheckOut(): void
    {
        $client = self::createClient();
        self::csrf($client); // start the session
        self::jsonRequest($client, 'POST', '/api/v1/orders', self::orderBody(), self::csrf($client));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('cart_empty', self::payload($client)['error']['code']);
    }

    public function testInsufficientStockRollsEverythingBack(): void
    {
        $client = self::createClient();
        $variantId = self::variant(2);
        $ordersBefore = (int) self::connection()->fetchOne('SELECT count(*) FROM orders');
        self::addToCart($client, $variantId, 2);

        // A competing purchase drains stock below the held quantity.
        $em = self::em();
        $variant = $em->find(ProductVariant::class, $variantId);
        self::assertInstanceOf(ProductVariant::class, $variant);
        $variant->reserve(1);
        $em->flush();

        self::jsonRequest($client, 'POST', '/api/v1/orders', self::orderBody(), self::csrf($client));
        self::assertResponseStatusCodeSame(422);
        $error = self::payload($client)['error'];
        self::assertSame('insufficient_stock', $error['code']);
        self::assertArrayHasKey('cart', $error['fields']);

        // Nothing persisted: stock untouched, cart intact, no order.
        self::assertSame(1, (int) self::connection()->fetchOne(
            'SELECT stock FROM product_variants WHERE id = :id',
            ['id' => $variantId],
        ));
        self::assertSame(2, self::cartCount($client));
        self::assertSame($ordersBefore, (int) self::connection()->fetchOne('SELECT count(*) FROM orders'));
    }

    public function testMeOrdersRequiresVerifiedEmail(): void
    {
        $client = self::createClient();
        $variantId = self::variant(5);
        $email = uniqid('orders', true).'@example.test';
        self::register($client, $email);
        self::addToCart($client, $variantId, 1);

        // Unverified -> 403 email_unverified.
        $client->request('GET', '/api/v1/me/orders');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('email_unverified', self::payload($client)['error']['code']);

        // Verify the account, then the list works.
        $em = self::em();
        $user = $em->getRepository(User::class)->findByEmail($email);
        self::assertInstanceOf(User::class, $user);
        $user->verifyEmail(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $em->flush();

        self::jsonRequest($client, 'POST', '/api/v1/orders', self::orderBody(), self::csrf($client));
        self::assertResponseStatusCodeSame(201);
        $number = self::payload($client)['data']['number'];

        $client->request('GET', '/api/v1/me/orders');
        self::assertResponseStatusCodeSame(200);
        $orders = self::payload($client)['data'];
        self::assertCount(1, $orders);
        self::assertSame($number, $orders[0]['number']);

        // The owner may also read the detail (user relation, not last_order).
        $client->request('GET', '/api/v1/orders/'.$number);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($number, self::payload($client)['data']['number']);
    }

    public function testGuestOrderStaysReadableAfterRegisterAndLogin(): void
    {
        $client = self::createClient();
        $variantId = self::variant(4);
        self::addToCart($client, $variantId, 1);
        self::jsonRequest($client, 'POST', '/api/v1/orders', self::orderBody(), self::csrf($client));
        self::assertResponseStatusCodeSame(201);
        $number = self::payload($client)['data']['number'];

        // Register in the same session: the last_order marker must keep
        // ownership even though the order has no user relation.
        self::register($client, uniqid('guestorder', true).'@example.test');
        $client->request('GET', '/api/v1/orders/'.$number);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($number, self::payload($client)['data']['number']);

        // Logout clears the marker; a foreign authenticated user gets 404.
        // Note: the status-code helpers only inspect the default test
        // client, so the stranger's responses are asserted directly.
        self::logout($client);
        $stranger = self::secondClient();
        self::jsonRequest($stranger, 'POST', '/api/v1/auth/register', [
            'display_name' => 'Stranger',
            'email' => uniqid('stranger', true).'@example.test',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'accepted_terms' => true,
        ], self::csrf($stranger));
        self::assertSame(201, $stranger->getResponse()->getStatusCode());
        $stranger->request('GET', '/api/v1/orders/'.$number);
        self::assertSame(404, $stranger->getResponse()->getStatusCode());
    }

    public function testOrderNumberCollisionRetriesWithFreshCandidate(): void
    {
        $client = self::createClient();
        $variantId = self::variant(2);
        self::addToCart($client, $variantId, 1);

        // A taken number: the first candidate must collide at flush.
        $em = self::em();
        $em->persist(new Order(
            'SP-DEADBEEF', 'taken@example.test', '+1', DeliveryMethod::Standard, 100, 0, 'EUR', 'eu_global', 'en',
        ));
        $em->flush();

        QueueOrderNumberSource::prime('SP-DEADBEEF', 'SP-C0FFEE42');
        try {
            self::jsonRequest($client, 'POST', '/api/v1/orders', self::orderBody(), self::csrf($client));
        } finally {
            QueueOrderNumberSource::reset();
        }

        self::assertResponseStatusCodeSame(201);
        self::assertSame(
            'SP-C0FFEE42',
            self::payload($client)['data']['number'],
            'A orders_number_unique collision must retry with the next candidate.',
        );
    }

    public function testAuthenticatedOrderCollisionRetryKeepsTheManagedUser(): void
    {
        $client = self::createClient();
        $variantId = self::variant(2);
        $email = uniqid('collision', true).'@example.test';
        self::register($client, $email);
        self::addToCart($client, $variantId, 1);

        // A taken number (distinct from the guest collision test above): the
        // first candidate collides at flush; the retry must attach the managed
        // user from the reloaded cart, not the detached Security user held
        // before the manager reset.
        $em = self::em();
        $em->persist(new Order(
            'SP-0D15EA5E', 'taken@example.test', '+1', DeliveryMethod::Standard, 100, 0, 'EUR', 'eu_global', 'en',
        ));
        $em->flush();

        QueueOrderNumberSource::prime('SP-0D15EA5E', 'SP-F00DBABE');
        try {
            self::jsonRequest($client, 'POST', '/api/v1/orders', self::orderBody(), self::csrf($client));
        } finally {
            QueueOrderNumberSource::reset();
        }

        self::assertResponseStatusCodeSame(201);
        self::assertSame('SP-F00DBABE', self::payload($client)['data']['number']);

        $userId = (int) self::connection()->fetchOne(
            'SELECT id FROM users WHERE email = :email',
            ['email' => $email],
        );
        $orderUserId = self::connection()->fetchOne(
            "SELECT user_id FROM orders WHERE number = 'SP-F00DBABE'",
        );
        self::assertSame($userId, (int) $orderUserId, 'The retried order must belong to the authenticated user.');

        // Ownership survives the retry: the same client can read the order.
        self::jsonRequest($client, 'GET', '/api/v1/orders/SP-F00DBABE');
        self::assertResponseStatusCodeSame(200);
    }

    public function testCheckoutInvalidatesTheCatalogCacheExactlyOnce(): void
    {
        $client = self::createClient();
        $variantId = self::variant(3);
        self::addToCart($client, $variantId, 1);

        RecordingCatalogCacheInvalidator::reset();
        self::jsonRequest($client, 'POST', '/api/v1/orders', self::orderBody(), self::csrf($client));
        self::assertResponseStatusCodeSame(201);

        self::assertSame(
            ['productsChanged'],
            RecordingCatalogCacheInvalidator::calls(),
            'A committed checkout must rotate the list/card generations once.',
        );
    }

    public function testRolledBackCheckoutDoesNotInvalidateTheCatalogCache(): void
    {
        $client = self::createClient();
        $variantId = self::variant(2);
        self::addToCart($client, $variantId, 2);

        // A competing purchase drains stock below the held quantity.
        $em = self::em();
        $variant = $em->find(ProductVariant::class, $variantId);
        self::assertInstanceOf(ProductVariant::class, $variant);
        $variant->reserve(1);
        $em->flush();

        RecordingCatalogCacheInvalidator::reset();
        self::jsonRequest($client, 'POST', '/api/v1/orders', self::orderBody(), self::csrf($client));
        self::assertResponseStatusCodeSame(422);

        self::assertSame(
            [],
            RecordingCatalogCacheInvalidator::calls(),
            'A rolled-back checkout must not rotate any generation.',
        );
    }

    public function testConcurrentInventoryReleaseRestoresStockExactlyOnce(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('pcntl/posix unavailable; idempotent release covered by unit tests.');
        }

        $client = self::createClient();
        $variantId = self::variant(2);
        self::addToCart($client, $variantId, 2);
        self::jsonRequest($client, 'POST', '/api/v1/orders', self::orderBody(), self::csrf($client));
        self::assertResponseStatusCodeSame(201);
        $orderId = (int) self::connection()->fetchOne(
            'SELECT id FROM orders ORDER BY id DESC LIMIT 1',
        );

        // Only scalars cross the fork: children boot their own kernel and
        // database connection, and the parent's lock connection is opened
        // after forking so no worker ever inherits its socket.
        $params = self::connection()->getParams();
        $mainBackend = (int) self::connection()->fetchOne('SELECT pg_backend_pid()');
        $dir = sys_get_temp_dir().'/release-race-'.bin2hex(random_bytes(4));
        mkdir($dir);

        $pids = [];
        foreach (['a', 'b'] as $worker) {
            $pid = pcntl_fork();
            if (-1 === $pid || false === $pid) {
                self::fail('pcntl_fork failed.');
            }
            if (0 === $pid) {
                self::releaseWorker($dir, $worker, $orderId);
                exit(1); // releaseWorker ends in SIGKILL; never reached.
            }
            $pids[$worker] = $pid;
        }

        $lock = null;
        try {
            $lock = DriverManager::getConnection($params);
            $lock->beginTransaction();
            $lock->fetchOne('SELECT id FROM orders WHERE id = :id FOR UPDATE', ['id' => $orderId]);
            file_put_contents($dir.'/lock_ready', '1');

            // Two genuinely separate PostgreSQL backends hold connections.
            $lockBackend = (int) $lock->fetchOne('SELECT pg_backend_pid()');
            self::assertNotSame($mainBackend, $lockBackend);

            self::assertTrue(
                self::waitForFile($dir.'/attempting-a'),
                'Worker A never reached the release call.',
            );
            self::assertTrue(
                self::waitForFile($dir.'/attempting-b'),
                'Worker B never reached the release call.',
            );
            // Both workers are inside release() and must be blocked on the
            // order row lock — neither may finish while the lock is held.
            usleep(400_000);
            self::assertFalse(
                file_exists($dir.'/result-a'),
                'Worker A finished while the order row lock was held — the lock path is broken.',
            );
            self::assertFalse(
                file_exists($dir.'/result-b'),
                'Worker B finished while the order row lock was held — the lock path is broken.',
            );

            $lock->commit();

            $resultA = self::workerResult($dir, 'a');
            $resultB = self::workerResult($dir, 'b');
            self::assertStringStartsWith('done:', $resultA);
            self::assertStringStartsWith('done:', $resultB);
            $backendA = (int) substr($resultA, 5);
            $backendB = (int) substr($resultB, 5);
            self::assertNotSame($backendA, $backendB, 'Workers ran on one shared connection.');
            self::assertNotSame($lockBackend, $backendA);
            self::assertNotSame($lockBackend, $backendB);

            self::assertSame(2, (int) $lock->fetchOne(
                'SELECT stock FROM product_variants WHERE id = :id',
                ['id' => $variantId],
            ), 'Two parallel release attempts must restore stock exactly once.');
            self::assertNotNull($lock->fetchOne(
                'SELECT inventory_released_at FROM orders WHERE id = :id',
                ['id' => $orderId],
            ));
        } finally {
            $lock?->close(); // releases the row lock so workers can exit
            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status);
            }
            foreach (['lock_ready', 'attempting-a', 'attempting-b', 'result-a', 'result-b'] as $file) {
                @unlink($dir.'/'.$file);
            }
            @rmdir($dir);
        }
    }

    /**
     * Forked worker: independent kernel/connection, waits for the parent's
     * row lock, runs a real release, records its own pg_backend_pid. Ends in
     * SIGKILL so no destructor can close an inherited socket under the parent.
     */
    private static function releaseWorker(string $dir, string $name, int $orderId): void
    {
        try {
            if (!self::waitForFile($dir.'/lock_ready')) {
                file_put_contents($dir.'/result-'.$name, 'error:no lock_ready');
            } else {
                self::bootKernel();
                $em = self::getContainer()->get('doctrine.orm.entity_manager');
                \assert($em instanceof EntityManagerInterface);
                $backend = (int) $em->getConnection()->fetchOne('SELECT pg_backend_pid()');
                file_put_contents($dir.'/attempting-'.$name, (string) $backend);
                $order = $em->find(Order::class, $orderId);
                $release = self::getContainer()->get(InventoryRelease::class);
                \assert($release instanceof InventoryRelease);
                $release->release($order, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
                file_put_contents($dir.'/result-'.$name, 'done:'.$backend);
            }
        } catch (\Throwable $exception) {
            file_put_contents($dir.'/result-'.$name, 'error:'.$exception->getMessage());
        }
        posix_kill(posix_getpid(), \SIGKILL);
    }

    private static function waitForFile(string $path, int $timeoutSeconds = 15): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            if (file_exists($path)) {
                return true;
            }
            usleep(20_000);
        }

        return false;
    }

    private static function workerResult(string $dir, string $name, int $timeoutSeconds = 15): string
    {
        $path = $dir.'/result-'.$name;
        if (!self::waitForFile($path, $timeoutSeconds)) {
            return 'missing';
        }

        return trim((string) file_get_contents($path));
    }

    public function testConcurrentCheckoutsCannotOversell(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('pcntl/posix unavailable; sequential oversell covered by rollback test.');
        }

        // Client A (parent) holds a cart for all remaining stock.
        $clientA = self::createClient();
        $variantId = self::variant(3);
        $ordersBefore = (int) self::connection()->fetchOne('SELECT count(*) FROM orders');
        self::addToCart($clientA, $variantId, 3);

        // An independent connection takes the variant row lock first.
        $params = self::connection()->getParams();
        $lock = DriverManager::getConnection($params);
        $lock->beginTransaction();
        $lockedStock = $lock->fetchOne(
            'SELECT stock FROM product_variants WHERE id = :id FOR UPDATE',
            ['id' => $variantId],
        );
        self::assertSame(3, (int) $lockedStock);

        $resultFile = (string) tempnam(sys_get_temp_dir(), 'order-race-');
        $pid = pcntl_fork();
        if (-1 === $pid || false === $pid) {
            self::fail('pcntl_fork failed.');
        }
        if (0 === $pid) {
            // Child: a genuinely separate kernel, session and DB connection.
            try {
                self::ensureKernelShutdown();
                $clientB = self::createClient();
                self::jsonRequest($clientB, 'POST', '/api/v1/cart/items', [
                    'variant_id' => $variantId,
                    'quantity' => 3,
                ], self::csrf($clientB));
                $code = $clientB->getResponse()->getStatusCode();
                if (201 === $code) {
                    self::jsonRequest($clientB, 'POST', '/api/v1/orders', self::orderBody(), self::csrf($clientB));
                    $code = $clientB->getResponse()->getStatusCode();
                }
                file_put_contents($resultFile, (string) $code);
            } catch (\Throwable $exception) {
                file_put_contents($resultFile, 'error:'.$exception->getMessage());
            }
            // Skip every destructor/shutdown hook — the child's fd copies must
            // not disturb the parent's connections.
            posix_kill(posix_getpid(), \SIGKILL);
            exit(1);
        }

        // Let the child reach the FOR UPDATE on the locked row, then release.
        usleep(700_000);
        $lock->commit();

        // The child unblocked, won the stock and committed. Parent now loses.
        self::jsonRequest($clientA, 'POST', '/api/v1/orders', self::orderBody(), self::csrf($clientA));
        $parentCode = $clientA->getResponse()->getStatusCode();

        pcntl_waitpid($pid, $status);
        $childResult = trim((string) file_get_contents($resultFile));
        unlink($resultFile);
        $lock->close();

        self::assertSame('201', $childResult, 'The lock holder released; the child checkout must succeed.');
        self::assertSame(422, $parentCode, 'The losing checkout must fail validation.');
        self::assertSame('insufficient_stock', self::payload($clientA)['error']['code']);
        self::assertSame(0, (int) self::connection()->fetchOne(
            'SELECT stock FROM product_variants WHERE id = :id',
            ['id' => $variantId],
        ), 'Stock must never go negative.');
        self::assertSame($ordersBefore + 1, (int) self::connection()->fetchOne('SELECT count(*) FROM orders'));
    }

    public function testRegistrationEnqueuesVerificationMail(): void
    {
        $client = self::createClient();
        self::register($client, uniqid('mail', true).'@example.test');

        // framework.mailer.message_bus routes SendEmailMessage to the async
        // doctrine transport — the attempt is provable via the queued row.
        $queued = (int) self::connection()->fetchOne(
            "SELECT count(*) FROM messenger_messages WHERE queue_name = 'async' AND body LIKE '%Verify your Sport E%'",
        );
        self::assertGreaterThanOrEqual(1, $queued, 'Registration must enqueue a verification email.');
    }
}
