<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cart;

use App\Application\Cart\CartView;
use App\Application\Commerce\PricingService;
use App\Domain\Cart\Entity\Cart;
use App\Domain\Catalog\Entity\Category;
use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Commerce\MarketContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CartView::class)]
final class CartViewTest extends TestCase
{
    private CartView $view;

    protected function setUp(): void
    {
        $this->view = new CartView(new PricingService(590, 1290, 5000));
    }

    private static function eurMarket(): MarketContext
    {
        return new MarketContext('DE', 'eu_global', 'EUR', 'en', 1900, '1.00000000');
    }

    /** @return array{0: Product, 1: ProductVariant} */
    private static function product(int $id, int $netCents, int $stock, string $sku): array
    {
        $product = new Product(new Category('cat-'.$id), 'product-'.$id, $netCents);
        $product->addTranslation('en', 'Product '.$id, 'Description '.$id);
        $product->addImage('/img/product-'.$id.'.png', null, 0);
        $variant = $product->addVariant($sku, null, $stock);
        $variant->addTranslation('en', 'Variant '.$id);
        (new \ReflectionProperty(ProductVariant::class, 'id'))->setValue($variant, $id);

        return [$product, $variant];
    }

    public function testViewAppliesConvertThenTaxLegacyRounding(): void
    {
        [, $variant] = self::product(21, 1000, 10, 'SKU-21');
        $cart = Cart::forGuest('token');
        $cart->addItem($variant, 2);

        $payload = $this->view->render($cart, self::eurMarket());

        self::assertSame(2, $payload['count']);
        $item = $payload['items'][0];
        // Legacy semantics: converted net 1000 -> VAT 19% on converted net.
        self::assertSame(1000, $item['net_unit_price_cents']);
        self::assertSame(1190, $item['unit_price_cents']);
        self::assertSame(2380, $item['line_total_cents']);
        self::assertSame(380, $item['tax_cents']);
        self::assertSame('EUR', $item['currency']);
        self::assertTrue($item['available']);
        self::assertSame('Product 21', $item['product']['name']);
        self::assertSame('/img/product-21.png', $item['product']['image_url']);
        self::assertSame('Variant 21', $item['variant']['name']);

        $summary = $payload['summary'];
        self::assertSame(2380, $summary['subtotal_cents']);
        self::assertSame(2000, $summary['net_subtotal_cents']);
        self::assertSame(380, $summary['tax_cents']);
        self::assertSame(702, $summary['delivery_quotes']['standard']);
        self::assertSame(1535, $summary['delivery_quotes']['express']);
        self::assertSame(590, $summary['delivery_net_quotes']['standard']);
        self::assertSame(1290, $summary['delivery_net_quotes']['express']);
        self::assertSame(112, $summary['delivery_tax_quotes']['standard']);
        self::assertSame(245, $summary['delivery_tax_quotes']['express']);
        self::assertSame(5000, $summary['free_standard_threshold']);
    }

    public function testInactiveProductLineIsFlaggedUnavailable(): void
    {
        [$product, $variant] = self::product(25, 500, 9, 'SKU-25');
        $cart = Cart::forGuest('token');
        $cart->addItem($variant, 2);
        $product->deactivate();

        $item = $this->view->render($cart, self::eurMarket())['items'][0];

        self::assertFalse($item['available'], 'A deactivated product must block checkout.');
        self::assertSame(9, $item['stock'], 'Stock is still reported for display.');
    }

    public function testViewRoundsConvertedNetBeforeApplyingVat(): void
    {
        [, $variant] = self::product(22, 1000, 10, 'SKU-22');
        $cart = Cart::forGuest('token');
        $cart->addItem($variant, 1);
        $market = new MarketContext('PL', 'poland', 'PLN', 'pl', 2300, '4.28000000');

        $item = $this->view->render($cart, $market)['items'][0];

        // 1000 EUR -> 4280 PLN net (half-up), then 23% VAT on 4280.
        self::assertSame(4280, $item['net_unit_price_cents']);
        self::assertSame(5264, $item['unit_price_cents']);
        self::assertSame('PLN', $item['currency']);
    }

    public function testLineBelowStockIsFlaggedUnavailable(): void
    {
        [, $variant] = self::product(23, 500, 3, 'SKU-23');
        $cart = Cart::forGuest('token');
        $cart->addItem($variant, 3);
        // A concurrent purchase drops stock below the held quantity.
        $variant->reserve(2);

        $item = $this->view->render($cart, self::eurMarket())['items'][0];

        self::assertFalse($item['available']);
        self::assertSame(1, $item['stock']);
    }

    public function testLocalizedNameFallsBackToEnglish(): void
    {
        [, $variant] = self::product(24, 500, 5, 'SKU-24');
        $cart = Cart::forGuest('token');
        $cart->addItem($variant, 1);
        $market = new MarketContext('PL', 'poland', 'PLN', 'pl', 2300, '4.28000000');

        $item = $this->view->render($cart, $market)['items'][0];

        self::assertSame('Product 24', $item['product']['name'], 'Missing pl translation must fall back to en.');
    }
}
