<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog;

use App\Application\Catalog\StorefrontCatalog;
use Doctrine\DBAL\Connection;

/**
 * PostgreSQL read model. Localized text resolves requested locale with a
 * required `en` row fallback via COALESCE; all inputs are bound parameters and
 * the sort is a fixed allowlist — nothing user-controlled reaches the SQL.
 */
final class DbalStorefrontCatalog implements StorefrontCatalog
{
    private const FALLBACK_LOCALE = 'en';
    private const MAX_FEATURED = 8;
    private const MAX_RELATED = 4;

    private const SORTS = [
        'newest' => 'p.created_at DESC, p.id DESC',
        'price_asc' => 'p.price_cents_net ASC, p.id ASC',
        'price_desc' => 'p.price_cents_net DESC, p.id ASC',
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function categories(string $locale): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT c.id, c.parent_id, c.slug, c.image_url, c.position,
                       COALESCE(ct.name, cte.name) AS name,
                       (SELECT count(*) FROM products p WHERE p.category_id = c.id AND p.active) AS direct_count
                FROM categories c
                JOIN category_translations cte ON cte.category_id = c.id AND cte.locale = :fallback
                LEFT JOIN category_translations ct ON ct.category_id = c.id AND ct.locale = :locale
                ORDER BY c.position, c.id
                SQL,
            ['locale' => $locale, 'fallback' => self::FALLBACK_LOCALE],
        );

        /** @var array<int, array<string, mixed>> $nodes */
        $nodes = [];
        foreach ($rows as $row) {
            $id = self::toInt($row['id'] ?? null);
            $parentId = $row['parent_id'] ?? null;
            $imageUrl = $row['image_url'] ?? null;
            $nodes[$id] = [
                'id' => $id,
                'slug' => self::toString($row['slug'] ?? null),
                'name' => self::toString($row['name'] ?? null),
                'image_url' => \is_string($imageUrl) ? $imageUrl : null,
                'product_count' => 0,
                'direct_count' => self::toInt($row['direct_count'] ?? null),
                'parent_id' => \is_numeric($parentId) ? self::toInt($parentId) : null,
            ];
        }

        /** @var array<int, list<array<string, mixed>>> $children */
        $children = [];
        $tree = [];
        foreach ($nodes as $id => $node) {
            $parentId = $node['parent_id'];
            if (\is_int($parentId) && isset($nodes[$parentId])) {
                $children[$parentId][] = $node;
            } else {
                $tree[] = $node;
            }
        }

        /**
         * @param array<string, mixed>                   $node
         * @param array<int, list<array<string, mixed>>> $children
         *
         * @return array<string, mixed>
         */
        $build = static function (array $node) use (&$build, $children): array {
            $count = self::toInt($node['direct_count'] ?? null);
            $kids = [];
            foreach ($children[self::toInt($node['id'] ?? null)] ?? [] as $child) {
                $kid = $build($child);
                $count += self::toInt($kid['product_count']);
                $kids[] = $kid;
            }
            unset($node['parent_id'], $node['direct_count']);
            $node['children'] = $kids;
            $node['product_count'] = $count;

            return $node;
        };

        /** @var list<array<string, mixed>> $result */
        $result = array_map($build, $tree);

        return $result;
    }

    public function featured(string $locale, int $limit = 8): array
    {
        $limit = max(1, min($limit, self::MAX_FEATURED));

        return $this->connection->fetchAllAssociative(
            $this->cardSelect().' WHERE p.active AND p.featured ORDER BY p.id DESC LIMIT :limit',
            ['locale' => $locale, 'fallback' => self::FALLBACK_LOCALE, 'limit' => $limit],
            ['limit' => \Doctrine\DBAL\ParameterType::INTEGER],
        );
    }

    public function page(
        string $locale,
        ?string $query,
        ?string $category,
        ?string $sort,
        int $page,
        int $perPage = 12,
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 48));
        $params = ['locale' => $locale, 'fallback' => self::FALLBACK_LOCALE];

        $where = 'p.active';
        $cte = '';
        if (null !== $category && '' !== $category) {
            $cte = <<<'SQL'
                WITH RECURSIVE cat_tree AS (
                    SELECT id FROM categories WHERE slug = :category
                    UNION ALL
                    SELECT c.id FROM categories c JOIN cat_tree t ON c.parent_id = t.id
                )
                SQL;
            $where .= ' AND p.category_id IN (SELECT id FROM cat_tree)';
            $params['category'] = $category;
        }
        if (null !== $query && '' !== $query) {
            $where .= " AND COALESCE(pt.name, pte.name) ILIKE :query ESCAPE '!'";
            $params['query'] = '%'.self::escapeLike($query).'%';
        }

        $total = self::toInt($this->connection->fetchOne(
            $cte.' SELECT count(*) FROM products p
                JOIN product_translations pte ON pte.product_id = p.id AND pte.locale = :fallback
                LEFT JOIN product_translations pt ON pt.product_id = p.id AND pt.locale = :locale
            WHERE '.$where,
            $params,
        ));

        $orderBy = self::SORTS[$sort ?? 'newest'] ?? self::SORTS['newest'];
        $items = $this->connection->fetchAllAssociative(
            $cte.' '.$this->cardSelect().' WHERE '.$where.' ORDER BY '.$orderBy.' LIMIT :limit OFFSET :offset',
            $params + ['limit' => $perPage, 'offset' => ($page - 1) * $perPage],
            ['limit' => \Doctrine\DBAL\ParameterType::INTEGER, 'offset' => \Doctrine\DBAL\ParameterType::INTEGER],
        );

        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    public function detail(string $locale, string $slug): ?array
    {
        $params = ['locale' => $locale, 'fallback' => self::FALLBACK_LOCALE, 'slug' => $slug];
        $product = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT p.id, p.slug, p.category_id, p.price_cents_net, p.compare_at_price_cents_net,
                       COALESCE(pt.name, pte.name) AS name,
                       COALESCE(pt.description, pte.description) AS description,
                       b.id AS brand_id, b.name AS brand_name, b.slug AS brand_slug,
                       m.id AS manufacturer_id, m.name AS manufacturer_name, m.slug AS manufacturer_slug
                FROM products p
                JOIN product_translations pte ON pte.product_id = p.id AND pte.locale = :fallback
                LEFT JOIN product_translations pt ON pt.product_id = p.id AND pt.locale = :locale
                LEFT JOIN brands b ON b.id = p.brand_id
                LEFT JOIN manufacturers m ON m.id = p.manufacturer_id
                WHERE p.slug = :slug AND p.active
                SQL,
            $params,
        );
        if (false === $product) {
            return null;
        }
        $productId = self::toInt($product['id'] ?? null);

        $variants = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT v.id, v.sku, v.stock,
                       COALESCE(v.price_cents_net, CAST(:base_price AS INTEGER)) AS price_cents_net,
                       COALESCE(vt.name, vte.name) AS name
                FROM product_variants v
                JOIN product_variant_translations vte ON vte.variant_id = v.id AND vte.locale = :fallback
                LEFT JOIN product_variant_translations vt ON vt.variant_id = v.id AND vt.locale = :locale
                WHERE v.product_id = :product_id
                ORDER BY v.id
                SQL,
            $params + ['product_id' => $productId, 'base_price' => self::toInt($product['price_cents_net'] ?? null)],
        );

        $images = $this->connection->fetchAllAssociative(
            'SELECT id, url, alt, position FROM product_images
             WHERE product_id = :product_id ORDER BY position',
            ['product_id' => $productId],
        );
        $specs = $this->connection->fetchAllAssociative(
            'SELECT name, value, unit FROM product_specifications
             WHERE product_id = :product_id ORDER BY position, id',
            ['product_id' => $productId],
        );

        return [
            'product' => $product + ['variants' => $variants, 'images' => $images, 'specifications' => $specs],
            'breadcrumbs' => $this->breadcrumbs($locale, self::toInt($product['category_id'] ?? null)),
            'related' => $this->related($locale, self::toInt($product['category_id'] ?? null), $productId),
        ];
    }

    /**
     * @param list<int> $ids
     *
     * @return list<array<string, mixed>>
     */
    public function cards(string $locale, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ([] === $ids) {
            return [];
        }

        return $this->connection->fetchAllAssociative(
            $this->cardSelect().' WHERE p.active AND p.id IN (:ids) ORDER BY p.id',
            ['locale' => $locale, 'fallback' => self::FALLBACK_LOCALE, 'ids' => $ids],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
        );
    }

    /** Shared card projection: identity, localized name, first image, net EUR price, stock. */
    private function cardSelect(): string
    {
        return <<<'SQL'
            SELECT p.id, p.slug, p.price_cents_net, p.compare_at_price_cents_net,
                   COALESCE(pt.name, pte.name) AS name,
                   (SELECT i.url FROM product_images i WHERE i.product_id = p.id ORDER BY i.position LIMIT 1) AS image_url,
                   (SELECT COALESCE(sum(v.stock), 0) FROM product_variants v WHERE v.product_id = p.id) AS stock
            FROM products p
            JOIN product_translations pte ON pte.product_id = p.id AND pte.locale = :fallback
            LEFT JOIN product_translations pt ON pt.product_id = p.id AND pt.locale = :locale
            SQL;
    }

    /** @return list<array{slug: string, name: string}> root-to-leaf */
    private function breadcrumbs(string $locale, int $categoryId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                WITH RECURSIVE lineage AS (
                    SELECT c.id, c.parent_id, c.slug, 0 AS depth
                    FROM categories c WHERE c.id = :category_id
                    UNION ALL
                    SELECT p.id, p.parent_id, p.slug, l.depth + 1
                    FROM categories p JOIN lineage l ON p.id = l.parent_id
                )
                SELECT l.slug, COALESCE(ct.name, cte.name) AS name, l.depth
                FROM lineage l
                JOIN category_translations cte ON cte.category_id = l.id AND cte.locale = :fallback
                LEFT JOIN category_translations ct ON ct.category_id = l.id AND ct.locale = :locale
                ORDER BY l.depth DESC
                SQL,
            ['category_id' => $categoryId, 'locale' => $locale, 'fallback' => self::FALLBACK_LOCALE],
        );

        return array_map(
            static fn (array $row): array => [
                'slug' => self::toString($row['slug'] ?? null),
                'name' => self::toString($row['name'] ?? null),
            ],
            $rows,
        );
    }

    /** @return list<array<string, mixed>> */
    private function related(string $locale, int $categoryId, int $excludeProductId): array
    {
        return $this->connection->fetchAllAssociative(
            $this->cardSelect().' WHERE p.active AND p.category_id = :category_id AND p.id <> :exclude
             ORDER BY p.id LIMIT :limit',
            [
                'locale' => $locale,
                'fallback' => self::FALLBACK_LOCALE,
                'category_id' => $categoryId,
                'exclude' => $excludeProductId,
                'limit' => self::MAX_RELATED,
            ],
            ['limit' => \Doctrine\DBAL\ParameterType::INTEGER],
        );
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    private static function toInt(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }

        return \is_numeric($value) ? (int) $value : 0;
    }

    private static function toString(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }
}
