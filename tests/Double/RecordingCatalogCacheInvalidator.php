<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Application\Catalog\CatalogCacheInvalidator;

/**
 * Test-env CatalogCacheInvalidator (config/services_test.yaml). Records
 * post-commit invalidation calls so functional tests can prove a checkout
 * or cancellation invalidated exactly once — and a rollback did not.
 */
final class RecordingCatalogCacheInvalidator extends CatalogCacheInvalidator
{
    /** @var list<string> */
    private static array $calls = [];

    public function __construct()
    {
        // Deliberately skips the parent constructor: no cache pool is
        // needed because calls are only recorded.
    }

    public static function reset(): void
    {
        self::$calls = [];
    }

    /** @return list<string> */
    public static function calls(): array
    {
        return self::$calls;
    }

    public static function count(): int
    {
        return \count(self::$calls);
    }

    public function productsChanged(): void
    {
        self::$calls[] = 'productsChanged';
    }
}
