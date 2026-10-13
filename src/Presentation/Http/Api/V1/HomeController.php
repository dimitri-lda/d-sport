<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\V1;

use App\Application\Catalog\CatalogView;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController
{
    public function __construct(private readonly CatalogView $catalog)
    {
    }

    #[Route('/api/v1/store/home', name: 'api_v1_store_home', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->catalog->home($request)]);
    }
}
