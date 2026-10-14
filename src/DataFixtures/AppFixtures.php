<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Domain\Cart\Entity\Cart;
use App\Domain\Catalog\Entity\Brand;
use App\Domain\Catalog\Entity\Category;
use App\Domain\Catalog\Entity\Manufacturer;
use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Identity\Entity\SavedAddress;
use App\Domain\Identity\Entity\User;
use App\Domain\Ordering\DeliveryMethod;
use App\Domain\Ordering\Entity\Order;
use App\Domain\Ordering\Entity\OrderAddress;
use App\Domain\Ordering\OrderStatus;
use App\Domain\Payment\Entity\PaymentAttempt;
use App\Domain\Payment\Entity\PaymentSession;
use App\Infrastructure\Crypto\FieldCipher;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Deterministic synthetic development data. Purge-and-load produces the same
 * row set, natural keys and counts every time; all identities are fictional.
 */
final class AppFixtures extends Fixture
{
    public const DEV_PASSWORD = 'SportE!2026';

    private const GUEST_CART_TOKEN = '10000000-0000-4000-8000-000000000001';
    private const FIXED_TIME = '2026-01-15T12:00:00+00:00';

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly FieldCipher $cipher,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        if ('prod' === $this->environment) {
            throw new \LogicException('Development fixtures must never run in prod.');
        }
        \assert($manager instanceof EntityManagerInterface);

        $customer = $this->loadUsers($manager);
        $variants = $this->loadCatalog($manager);
        $this->loadCommerceRows($manager);
        $this->loadAddressAndCarts($manager, $customer, $variants);
        $this->loadOrders($manager, $customer, $variants);
        $manager->flush();
        $this->normalizeTimestamps($manager);
    }

    /**
     * ORM entities stamp created_at/updated_at at construction time; normalize
     * them so two purge-and-load runs produce identical business timestamps.
     */
    private function normalizeTimestamps(EntityManagerInterface $manager): void
    {
        $connection = $manager->getConnection();
        foreach ([
            'users', 'saved_addresses', 'categories', 'products', 'product_variants',
            'product_images', 'product_specifications', 'carts', 'cart_items',
            'orders', 'order_addresses', 'order_items', 'payment_sessions', 'payment_attempts',
        ] as $table) {
            $connection->executeStatement(
                \sprintf('UPDATE %s SET created_at = :ts, updated_at = :ts', $table),
                ['ts' => '2026-01-15 12:00:00+00'],
            );
        }
    }

    private function loadUsers(ObjectManager $manager): User
    {
        $at = new \DateTimeImmutable('2026-01-10T09:00:00+00:00');
        $customer = new User('customer@example.test', 'pending', 'Demo Customer');
        $customer->changePassword($this->passwordHasher->hashPassword($customer, self::DEV_PASSWORD));
        $customer->verifyEmail($at);
        $customer->acceptTerms($at);

        $unverified = new User('unverified@example.test', 'pending', 'Pending User');
        $unverified->changePassword($this->passwordHasher->hashPassword($unverified, self::DEV_PASSWORD));
        $unverified->acceptTerms($at);

        $admin = new User('admin@example.test', 'pending', 'Store Admin', ['ROLE_ADMIN']);
        $admin->changePassword($this->passwordHasher->hashPassword($admin, self::DEV_PASSWORD));
        $admin->verifyEmail($at);
        $admin->acceptTerms($at);

        $manager->persist($customer);
        $manager->persist($unverified);
        $manager->persist($admin);

        return $customer;
    }

    /** @return array<string, ProductVariant> variants keyed by product slug */
    private function loadCatalog(ObjectManager $manager): array
    {
        $roots = [
            'running' => ['Running', 'Бег', 'Bieganie'],
            'fitness' => ['Fitness', 'Фитнес', 'Fitness'],
            'tennis' => ['Racket sports', 'Ракеточные виды спорта', 'Sporty rakietowe'],
            'outdoor' => ['Outdoor', 'Активный отдых', 'Outdoor'],
        ];
        $children = [
            'running' => [
                'running-shoes' => ['Running shoes', 'Беговая обувь', 'Buty do biegania'],
                'running-clothing' => ['Running clothing', 'Одежда для бега', 'Odzież do biegania'],
            ],
            'fitness' => [
                'training-accessories' => ['Training accessories', 'Тренировочные аксессуары', 'Akcesoria treningowe'],
                'weights' => ['Weights', 'Отягощения', 'Obciążenia'],
            ],
            'tennis' => [
                'rackets' => ['Rackets', 'Ракетки', 'Rakiety'],
                'tennis-bags' => ['Tennis bags', 'Теннисные сумки', 'Torby tenisowe'],
            ],
            'outdoor' => [
                'outdoor-clothing' => ['Outdoor clothing', 'Одежда для активного отдыха', 'Odzież outdoor'],
                'backpacks' => ['Backpacks', 'Рюкзаки', 'Plecaki'],
            ],
        ];

        /** @var array<string, Category> $categories */
        $categories = [];
        $position = 0;
        foreach ($roots as $slug => [$en, $ru, $pl]) {
            $category = new Category($slug, null, $position++, $this->image('categories', $slug));
            $category->addTranslation('en', $en);
            $category->addTranslation('ru', $ru);
            $category->addTranslation('pl', $pl);
            $manager->persist($category);
            $categories[$slug] = $category;
        }
        foreach ($children as $parentSlug => $items) {
            $childPosition = 0;
            foreach ($items as $slug => [$en, $ru, $pl]) {
                $category = new Category($slug, $categories[$parentSlug], $childPosition++);
                $category->addTranslation('en', $en);
                $category->addTranslation('ru', $ru);
                $category->addTranslation('pl', $pl);
                $manager->persist($category);
                $categories[$slug] = $category;
            }
        }

        /** @var array<string, Brand> $brands */
        $brands = [];
        /** @var array<string, Manufacturer> $manufacturers */
        $manufacturers = [];
        foreach (['On', 'daoSport', 'Babolat', 'Salomon', 'Nike', 'Wilson', 'Osprey'] as $name) {
            $slug = strtolower(str_replace(' ', '-', $name));
            $brands[$name] = new Brand($name, $slug);
            $manufacturers[$name] = new Manufacturer($name, $slug);
            $manager->persist($brands[$name]);
            $manager->persist($manufacturers[$name]);
        }

        /** @var array<string, ProductVariant> $variants */
        $variants = [];
        foreach ($this->productData() as $data) {
            $product = new Product(
                $categories[$data['category']],
                $data['slug'],
                $data['price'],
                $brands[$data['brand']],
                $manufacturers[$data['brand']],
                $data['compare_at'] ?? null,
                $data['featured'] ?? false,
                $data['active'] ?? true,
            );
            foreach ($data['names'] as $locale => $name) {
                $product->addTranslation($locale, $name, $data['descriptions'][$locale]);
            }
            $variant = $product->addVariant(
                strtoupper($data['slug']).'-ONE',
                $data['variant_price'] ?? null,
                $data['stock'] ?? 24,
            );
            $variant->addTranslation('en', 'One size');
            $variant->addTranslation('ru', 'Один размер');
            $variant->addTranslation('pl', 'Rozmiar uniwersalny');
            $product->addImage($this->image('products', $data['slug']), $data['names']['en'].' — demo image', 0);
            if ($data['gallery'] ?? false) {
                $product->addImage(
                    $this->image('products', $data['slug'].'-alt'),
                    $data['names']['en'].' — alternate view',
                    1,
                );
            }
            foreach ($data['specs'] ?? [] as $specPosition => [$name, $value, $unit]) {
                $product->addSpecification($name, $value, $unit, $specPosition);
            }
            $manager->persist($product);
            $variants[$data['slug']] = $variant;
        }

        return $variants;
    }

    /**
     * @return list<array{
     *     slug: string,
     *     category: string,
     *     brand: string,
     *     price: int,
     *     names: array<string, string>,
     *     descriptions: array<string, string>,
     *     featured?: bool,
     *     active?: bool,
     *     compare_at?: int,
     *     variant_price?: int,
     *     stock?: int,
     *     gallery?: bool,
     *     specs?: list<array{0: string, 1: string, 2: string|null}>,
     * }>
     */
    private function productData(): array
    {
        return [
            [
                'slug' => 'cloudswift-4', 'category' => 'running-shoes', 'brand' => 'On',
                'price' => 16900, 'featured' => true, 'gallery' => true,
                'names' => ['en' => 'Cloudswift 4', 'ru' => 'Cloudswift 4', 'pl' => 'Cloudswift 4'],
                'descriptions' => [
                    'en' => 'A responsive everyday runner with soft landings and confident grip.',
                    'ru' => 'Отзывчивые кроссовки на каждый день с мягкой посадкой.',
                    'pl' => 'Responsywne buty do biegania na co dzień.',
                ],
                'specs' => [['Purpose', 'Road running', null], ['Weight', '260', 'g']],
            ],
            [
                'slug' => 'move-training-mat', 'category' => 'training-accessories', 'brand' => 'daoSport',
                'price' => 4900, 'featured' => true, 'variant_price' => 5900,
                'names' => ['en' => 'Move Training Mat', 'ru' => 'Коврик Move', 'pl' => 'Mata Move'],
                'descriptions' => [
                    'en' => 'A grippy, comfortable mat for your home and studio practice.',
                    'ru' => 'Удобный коврик с хорошим сцеплением для дома и студии.',
                    'pl' => 'Wygodna, antypoślizgowa mata do ćwiczeń.',
                ],
                'specs' => [['Purpose', 'Home and studio training', null]],
            ],
            [
                'slug' => 'pure-aero-98', 'category' => 'rackets', 'brand' => 'Babolat',
                'price' => 22900, 'featured' => true,
                'names' => ['en' => 'Pure Aero 98', 'ru' => 'Pure Aero 98', 'pl' => 'Pure Aero 98'],
                'descriptions' => [
                    'en' => 'Spin, precision and feel for the player who controls every point.',
                    'ru' => 'Вращение, точность и чувство мяча в каждом розыгрыше.',
                    'pl' => 'Rotacja, precyzja i czucie dla wymagających graczy.',
                ],
                'specs' => [['Discipline', 'Tennis', null]],
            ],
            [
                'slug' => 'trail-shell-jacket', 'category' => 'outdoor-clothing', 'brand' => 'Salomon',
                'price' => 13900, 'featured' => true,
                'names' => ['en' => 'Trail Shell Jacket', 'ru' => 'Куртка Trail Shell', 'pl' => 'Kurtka Trail Shell'],
                'descriptions' => [
                    'en' => 'A light, packable shell for changeable trail weather.',
                    'ru' => 'Лёгкая компактная куртка для переменчивой погоды.',
                    'pl' => 'Lekka, pakowana kurtka na zmienną pogodę.',
                ],
                'specs' => [['Purpose', 'Mountain activities', null]],
            ],
            [
                'slug' => 'aero-run-shorts', 'category' => 'running-clothing', 'brand' => 'Nike',
                'price' => 4500, 'compare_at' => 5900,
                'names' => ['en' => 'Aero Run Shorts', 'ru' => 'Шорты Aero Run', 'pl' => 'Spodenki Aero Run'],
                'descriptions' => [
                    'en' => 'Light, breathable shorts that stay out of your way.',
                    'ru' => 'Лёгкие дышащие шорты для бега.',
                    'pl' => 'Lekkie, oddychające spodenki do biegania.',
                ],
                'specs' => [['Type', 'Running shorts', null]],
            ],
            [
                'slug' => 'kettlebell-12kg', 'category' => 'weights', 'brand' => 'daoSport',
                'price' => 5900,
                'names' => ['en' => 'Kettlebell 12 kg', 'ru' => 'Гиря 12 кг', 'pl' => 'Kettlebell 12 kg'],
                'descriptions' => [
                    'en' => 'A durable cast iron kettlebell for full-body strength sessions.',
                    'ru' => 'Прочная чугунная гиря для силовых тренировок.',
                    'pl' => 'Trwały odlew żeliwny do treningu siłowego.',
                ],
                'specs' => [['Material', 'Cast iron', null], ['Mass', '12', 'kg']],
            ],
            [
                'slug' => 'court-backpack', 'category' => 'tennis-bags', 'brand' => 'Wilson',
                'price' => 7900, 'stock' => 0,
                'names' => ['en' => 'Court Backpack', 'ru' => 'Рюкзак Court', 'pl' => 'Plecak Court'],
                'descriptions' => [
                    'en' => 'Smart storage for your racket, kit and everyday essentials.',
                    'ru' => 'Вместительный рюкзак для ракетки и экипировки.',
                    'pl' => 'Pojemny plecak na rakietę i akcesoria.',
                ],
                'specs' => [['Purpose', 'Racket and kit transport', null]],
            ],
            [
                // Deliberately missing the `pl` translation to cover the en fallback path.
                'slug' => 'alpine-daypack-24l', 'category' => 'backpacks', 'brand' => 'Osprey',
                'price' => 10900,
                'names' => ['en' => 'Alpine Daypack 24L', 'ru' => 'Рюкзак Alpine 24L'],
                'descriptions' => [
                    'en' => 'A comfortable, versatile daypack for every trail.',
                    'ru' => 'Удобный универсальный рюкзак для любых маршрутов.',
                ],
                'specs' => [['Capacity', '24', 'l']],
            ],
            [
                // Inactive product: must never appear in storefront listings.
                'slug' => 'legacy-runner-cap', 'category' => 'running-clothing', 'brand' => 'daoSport',
                'price' => 2900, 'active' => false,
                'names' => ['en' => 'Legacy Runner Cap', 'ru' => 'Кепка Legacy', 'pl' => 'Czapka Legacy'],
                'descriptions' => [
                    'en' => 'A discontinued lightweight running cap kept for order history.',
                    'ru' => 'Снятая с производства лёгкая кепка для бега.',
                    'pl' => 'Wycofana lekka czapka do biegania.',
                ],
            ],
        ];
    }

    private function loadCommerceRows(ObjectManager $manager): void
    {
        \assert($manager instanceof EntityManagerInterface);
        $connection = $manager->getConnection();
        // DBAL-owned tables are outside the ORM purger's reach; clear them for rerun safety.
        $connection->executeStatement('DELETE FROM shopify_webhook_deliveries');
        $connection->executeStatement('DELETE FROM exchange_rates');
        $quotedAt = '2026-01-01 00:00:00+00';
        foreach ([
            ['currency' => 'EUR', 'rate' => '1.00000000', 'source' => 'base'],
            ['currency' => 'BYN', 'rate' => '3.45000000', 'source' => 'myfin'],
            ['currency' => 'PLN', 'rate' => '4.28000000', 'source' => 'walutomat'],
        ] as $row) {
            $connection->insert('exchange_rates', $row + ['quoted_at' => $quotedAt, 'updated_at' => $quotedAt]);
        }

        $connection->insert('shopify_webhook_deliveries', [
            'delivery_id' => '20000000-0000-4000-8000-000000000001',
            'topic' => 'orders/paid',
            'processed_at' => '2026-01-15 12:05:00+00',
            'created_at' => '2026-01-15 12:04:00+00',
            'updated_at' => '2026-01-15 12:05:00+00',
        ]);
        $connection->insert('shopify_webhook_deliveries', [
            'delivery_id' => '20000000-0000-4000-8000-000000000002',
            'topic' => 'orders/updated',
            'processed_at' => null,
            'created_at' => '2026-01-15 12:30:00+00',
            'updated_at' => '2026-01-15 12:30:00+00',
        ]);
    }

    /** @param array<string, ProductVariant> $variants */
    private function loadAddressAndCarts(ObjectManager $manager, User $customer, array $variants): void
    {
        $manager->persist(new SavedAddress(
            $customer,
            'Anna',
            'Nowak',
            'Marszałkowska 1/2',
            'apt 5',
            'Warszawa',
            '00-001',
            'PL',
        ));

        $guestCart = Cart::forGuest(self::GUEST_CART_TOKEN);
        $guestCart->addOrReplaceItem($variants['cloudswift-4'], 1);
        $manager->persist($guestCart);

        $userCart = Cart::forUser($customer);
        $userCart->addOrReplaceItem($variants['cloudswift-4'], 2);
        $userCart->addOrReplaceItem($variants['kettlebell-12kg'], 1);
        $manager->persist($userCart);
    }

    /** @param array<string, ProductVariant> $variants */
    private function loadOrders(ObjectManager $manager, User $customer, array $variants): void
    {
        $at = new \DateTimeImmutable(self::FIXED_TIME);
        $sequence = 0;

        $pending = $this->makeOrder($customer, ++$sequence, [$this->orderLine($variants['cloudswift-4'])]);
        $paid = $this->makeOrder($customer, ++$sequence, [$this->orderLine($variants['move-training-mat'])]);
        $processing = $this->makeOrder($customer, ++$sequence, [$this->orderLine($variants['pure-aero-98'])]);
        $shipped = $this->makeOrder($customer, ++$sequence, [$this->orderLine($variants['aero-run-shorts'])]);
        $delivered = $this->makeOrder($customer, ++$sequence, [$this->orderLine($variants['trail-shell-jacket'])]);
        $cancelled = $this->makeOrder($customer, ++$sequence, [$this->orderLine($variants['kettlebell-12kg'])]);
        $refunded = $this->makeOrder($customer, ++$sequence, [$this->orderLine($variants['alpine-daypack-24l'])]);

        $paid->transitionTo(OrderStatus::Paid, $at);
        $paid->reserveInventory($at);
        $processing->transitionTo(OrderStatus::Paid, $at);
        $processing->transitionTo(OrderStatus::Processing, $at);
        $processing->reserveInventory($at);
        $shipped->transitionTo(OrderStatus::Paid, $at);
        $shipped->transitionTo(OrderStatus::Processing, $at);
        $shipped->transitionTo(OrderStatus::Shipped, $at);
        $shipped->reserveInventory($at);
        $delivered->transitionTo(OrderStatus::Paid, $at);
        $delivered->transitionTo(OrderStatus::Processing, $at);
        $delivered->transitionTo(OrderStatus::Shipped, $at);
        $delivered->transitionTo(OrderStatus::Delivered, $at);
        $delivered->reserveInventory($at);
        $cancelled->reserveInventory($at);
        $cancelled->releaseInventory($at);
        $cancelled->transitionTo(OrderStatus::Cancelled, $at);
        $refunded->transitionTo(OrderStatus::Paid, $at);
        $refunded->transitionTo(OrderStatus::Refunded, $at);
        $refunded->reserveInventory($at);

        foreach ([$pending, $paid, $processing, $shipped, $delivered, $cancelled, $refunded] as $order) {
            $manager->persist($order);
        }

        $this->loadPayments($manager, $pending, $paid, $at);
    }

    /** @param list<array{0: ProductVariant, 1: int, 2: int}> $lines */
    private function makeOrder(User $customer, int $sequence, array $lines): Order
    {
        $subtotal = 0;
        foreach ($lines as [, $quantity, $price]) {
            $subtotal += $quantity * $price;
        }
        $order = new Order(
            \sprintf('SP-%08d', $sequence),
            'customer@example.test',
            '+48 600 000 000',
            DeliveryMethod::Standard,
            $subtotal,
            590,
            'PLN',
            'PL',
            'pl',
            $customer,
            'EUR',
            '4.28000000',
            2300,
        );
        $order->setAddress(new OrderAddress(
            $order, 'Anna', 'Nowak', 'Marszałkowska 1/2', 'apt 5', 'Warszawa', '00-001', 'PL',
        ));
        foreach ($lines as [$variant, $quantity, $price]) {
            $name = $variant->getProduct()->getTranslation('en')?->getName() ?? 'Fixture product';
            $order->addItem($variant, $name, 'One size', $price, $quantity, $price, 0);
        }

        return $order;
    }

    /** @return array{0: ProductVariant, 1: int, 2: int} */
    private function orderLine(ProductVariant $variant): array
    {
        return [$variant, 1, $variant->getPriceCentsNet()];
    }

    private function loadPayments(ObjectManager $manager, Order $pending, Order $paid, \DateTimeImmutable $at): void
    {
        $sessionPending = new PaymentSession($pending, 'fixture-'.$pending->getNumber());
        $sessionPending->markReady(
            $this->cipher->encrypt('fixture-session-token', $this->ctx('payment_sessions.session_token_encrypted', $sessionPending)),
            hash('sha256', 'fixture-session-token-'.$pending->getNumber()),
            'https://payments.example.test/checkout/fixture-00001',
            $at,
        );
        $attemptPending = new PaymentAttempt(
            $sessionPending,
            hash('sha256', 'fixture-payment-method'),
            '30000000-0000-4000-8000-000000000001',
            $this->cipher->encrypt('{"fixture":true}', $this->attemptCtx('payload_encrypted', '30000000-0000-4000-8000-000000000001')),
            $pending->getTotalCents(),
            'PLN',
        );
        $manager->persist($sessionPending);
        $manager->persist($attemptPending);

        $sessionPaid = new PaymentSession($paid, 'fixture-'.$paid->getNumber());
        $sessionPaid->markReady(
            $this->cipher->encrypt('fixture-session-token', $this->ctx('payment_sessions.session_token_encrypted', $sessionPaid)),
            hash('sha256', 'fixture-session-token-'.$paid->getNumber()),
            'https://payments.example.test/checkout/fixture-00002',
            $at,
        );
        $sessionPaid->markProcessing('shoppay-fixture-00002', $at);
        $sessionPaid->markPaid(
            $this->cipher->encrypt('fixture-receipt-token', $this->ctx('payment_sessions.receipt_token_encrypted', $sessionPaid)),
            $at,
        );
        $attemptPaid = new PaymentAttempt(
            $sessionPaid,
            hash('sha256', 'fixture-payment-method'),
            '30000000-0000-4000-8000-000000000002',
            $this->cipher->encrypt('{"fixture":true}', $this->attemptCtx('payload_encrypted', '30000000-0000-4000-8000-000000000002')),
            $paid->getTotalCents(),
            'PLN',
        );
        $attemptPaid->markProcessing($at);
        $attemptPaid->markCompleted(
            $this->cipher->encrypt('fixture-receipt-token', $this->attemptCtx('receipt_token_encrypted', '30000000-0000-4000-8000-000000000002')),
            $at,
        );
        $manager->persist($sessionPaid);
        $manager->persist($attemptPaid);
    }

    private function ctx(string $column, PaymentSession $session): string
    {
        return $column.'.'.$session->getSourceIdentifier();
    }

    private function attemptCtx(string $column, string $idempotencyKey): string
    {
        return 'payment_attempts.'.$column.'.'.$idempotencyKey;
    }

    private function image(string $kind, string $slug): string
    {
        return '/fixtures/images/'.$kind.'/'.$slug.'.webp';
    }
}
