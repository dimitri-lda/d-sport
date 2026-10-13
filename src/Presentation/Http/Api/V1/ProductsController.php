<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\V1;

use App\Application\Catalog\CatalogView;
use App\Presentation\Http\ApiProblem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class ProductsController
{
    private const SORTS = ['newest', 'price_asc', 'price_desc'];
    private const SLUG_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';
    private const MAX_QUERY_LENGTH = 120;

    public function __construct(private readonly CatalogView $catalog)
    {
    }

    #[Route('/api/v1/products', name: 'api_v1_products', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $query = $request->query->get('q');
        $category = $request->query->get('category');
        $sort = $request->query->get('sort');
        $rawPage = $request->query->get('page');

        $fields = [];
        $q = null;
        if (null !== $query && '' !== $query) {
            if (!\is_string($query) || mb_strlen($query) > self::MAX_QUERY_LENGTH) {
                $fields['q'] = ['The search query must be at most 120 characters.'];
            } else {
                $q = $query;
            }
        }
        $categorySlug = null;
        if (\is_string($category) && '' !== $category) {
            if (1 !== preg_match(self::SLUG_PATTERN, $category)) {
                $fields['category'] = ['The category filter must be a valid slug.'];
            } else {
                $categorySlug = $category;
            }
        }
        $sortValue = null;
        if (\is_string($sort) && '' !== $sort) {
            if (!\in_array($sort, self::SORTS, true)) {
                $fields['sort'] = ['The sort must be one of: newest, price_asc, price_desc.'];
            } else {
                $sortValue = $sort;
            }
        }
        $page = 1;
        if (null !== $rawPage && '' !== $rawPage) {
            if (1 !== preg_match('/^\d+$/', (string) $rawPage) || (int) $rawPage < 1) {
                $fields['page'] = ['The page must be a positive integer.'];
            } else {
                $page = (int) $rawPage;
            }
        }
        if ([] !== $fields) {
            throw new ApiProblem('validation_failed', 'The given catalog filters are invalid.', 422, $fields);
        }

        $result = $this->catalog->page($request, $q, $categorySlug, $sortValue, $page);

        return new JsonResponse([
            'data' => $result['items'],
            'meta' => ['page' => $result['page'], 'per_page' => $result['per_page'], 'total' => $result['total']],
        ]);
    }

    #[Route('/api/v1/products/{slug}', name: 'api_v1_products_show', requirements: ['slug' => '[a-z0-9]+(-[a-z0-9]+)*'], methods: ['GET'])]
    public function show(Request $request, string $slug): JsonResponse
    {
        $detail = $this->catalog->detail($request, $slug);
        if (null === $detail) {
            throw new ApiProblem('not_found', 'The requested product does not exist.', 404);
        }

        return new JsonResponse(['data' => $detail]);
    }
}
