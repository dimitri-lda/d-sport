<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Application\Catalog\StorefrontCatalog;

/**
 * Source read model recording invocations per operation. Returns fixed
 * payloads; the assertions are about call counts and arguments.
 */
final class RecordingStorefrontCatalog implements StorefrontCatalog
{
    /** @var array<string, int> */
    public array $calls = ['categories' => 0, 'featured' => 0, 'page' => 0, 'detail' => 0, 'cards' => 0];

    /** @var list<array<string, mixed>> */
    public array $pageArgs = [];

    /** @var list<list<int>> */
    public array $cardsIds = [];

    public function categories(string $locale): array
    {
        ++$this->calls['categories'];

        return [['name' => 'tree']];
    }

    public function featured(string $locale, int $limit = 8): array
    {
        ++$this->calls['featured'];

        return [['id' => 1]];
    }

    public function page(
        string $locale,
        ?string $query,
        ?string $category,
        ?string $sort,
        int $page,
        int $perPage = 12,
    ): array {
        ++$this->calls['page'];
        $this->pageArgs[] = [
            'query' => $query, 'category' => $category, 'sort' => $sort,
            'page' => $page, 'per_page' => $perPage,
        ];

        return ['items' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage];
    }

    public function detail(string $locale, string $slug): ?array
    {
        ++$this->calls['detail'];

        return ['product' => ['slug' => $slug]];
    }

    public function cards(string $locale, array $ids): array
    {
        ++$this->calls['cards'];
        $this->cardsIds[] = $ids;

        return [['id' => $ids[0] ?? 0]];
    }
}
