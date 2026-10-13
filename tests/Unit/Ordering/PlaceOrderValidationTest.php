<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Application\Cart\CartManager;
use App\Application\Catalog\CatalogCacheInvalidator;
use App\Application\Commerce\CountryCatalog;
use App\Application\Commerce\CountryLookup;
use App\Application\Commerce\ExchangeRateProvider;
use App\Application\Commerce\MarketContextResolver;
use App\Application\Commerce\PricingService;
use App\Application\Ordering\OrderNumberGenerator;
use App\Application\Ordering\PlaceOrder;
use App\Application\Ordering\PlaceOrderInput;
use App\Application\Ordering\RandomOrderNumberSource;
use App\Domain\Cart\Repository\CartRepository;
use App\Domain\Catalog\Repository\ProductVariantRepository;
use App\Domain\Commerce\ExchangeRate;
use App\Presentation\Http\ApiProblem;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

#[CoversClass(PlaceOrder::class)]
final class PlaceOrderValidationTest extends TestCase
{
    private PlaceOrder $service;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(
            fn (callable $callback): mixed => $callback($em),
        );
        $security = $this->createMock(Security::class);
        $countries = new CountryCatalog(
            ['PL' => ['code' => 'poland', 'currency' => 'PLN', 'locale' => 'pl', 'vat_basis_points' => 2300]],
            ['DE' => 1900],
            ['PL' => ['en' => 'Poland'], 'DE' => ['en' => 'Germany']],
            ['en', 'pl'],
            'DE',
            'eu_global',
        );
        $rates = $this->createMock(ExchangeRateProvider::class);
        $rates->method('rate')->willReturnCallback(static fn (string $currency): ExchangeRate => new ExchangeRate(
            $currency,
            'EUR' === $currency ? '1.00000000' : '4.28000000',
            'test',
            new \DateTimeImmutable(),
        ));
        $this->service = new PlaceOrder(
            new CartManager(
                $this->createMock(CartRepository::class),
                $this->createMock(ProductVariantRepository::class),
                $em,
                $this->createMock(ManagerRegistry::class),
                $security,
            ),
            new MarketContextResolver($countries, $this->createMock(CountryLookup::class), $rates),
            $countries,
            new PricingService(590, 1290, 5000),
            new OrderNumberGenerator(new RandomOrderNumberSource()),
            $em,
            $this->createMock(ManagerRegistry::class),
            $this->createMock(CatalogCacheInvalidator::class),
        );
        $this->now = new \DateTimeImmutable('2026-02-02T12:00:00+00:00');
    }

    /** @param array<string, string|null> $overrides */
    private static function input(array $overrides = []): PlaceOrderInput
    {
        return new PlaceOrderInput(
            $overrides['email'] ?? 'buyer@example.test',
            $overrides['phone'] ?? '+49123456789',
            $overrides['first_name'] ?? 'Jane',
            $overrides['last_name'] ?? 'Doe',
            $overrides['line1'] ?? 'Main St 1',
            $overrides['line2'] ?? null,
            $overrides['city'] ?? 'Berlin',
            $overrides['postal_code'] ?? '10115',
            $overrides['country'] ?? 'DE',
            $overrides['delivery_method'] ?? 'standard',
        );
    }

    private static function request(): Request
    {
        $request = Request::create('/api/v1/orders');
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function expectValidationFailure(PlaceOrderInput $input, string $field): void
    {
        try {
            $this->service->place(self::request(), $input, $this->now);
            self::fail('Expected validation_failed.');
        } catch (ApiProblem $problem) {
            self::assertSame('validation_failed', $problem->errorCode);
            self::assertSame(422, $problem->status);
            self::assertArrayHasKey($field, $problem->fields);
        }
    }

    public function testRejectsInvalidFields(): void
    {
        $this->expectValidationFailure(self::input(['email' => 'not-an-email']), 'email');
        $this->expectValidationFailure(self::input(['phone' => '']), 'phone');
        $this->expectValidationFailure(self::input(['first_name' => '']), 'first_name');
        $this->expectValidationFailure(self::input(['line1' => str_repeat('x', 181)]), 'line1');
        $this->expectValidationFailure(self::input(['country' => 'US']), 'country');
        $this->expectValidationFailure(self::input(['country' => 'ZZZ']), 'country');
        $this->expectValidationFailure(self::input(['delivery_method' => 'drone']), 'delivery_method');
    }

    public function testValidInputReachesEmptyCartRejection(): void
    {
        // Guest cart resolves to a fresh empty cart -> cart_empty, proving the
        // payload itself passed validation.
        try {
            $this->service->place(self::request(), self::input(), $this->now);
            self::fail('Expected cart_empty.');
        } catch (ApiProblem $problem) {
            self::assertSame('cart_empty', $problem->errorCode);
            self::assertSame(422, $problem->status);
            self::assertArrayHasKey('cart', $problem->fields);
        }
    }
}
