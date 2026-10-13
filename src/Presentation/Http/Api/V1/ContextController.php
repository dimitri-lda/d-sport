<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\V1;

use App\Presentation\Http\Api\ContextView;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class ContextController
{
    public function __construct(private readonly ContextView $contextView)
    {
    }

    #[Route('/api/v1/context', name: 'api_v1_context', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->contextView->current($request)]);
    }
}
