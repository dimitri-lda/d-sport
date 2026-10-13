<?php

declare(strict_types=1);

namespace App\Controller;

use App\Health\ReadinessChecker;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    #[Route('/api/health/live', name: 'health_live', methods: ['GET'])]
    public function live(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok'], headers: ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/health/ready', name: 'health_ready', methods: ['GET'])]
    public function ready(ReadinessChecker $checker): JsonResponse
    {
        $result = $checker->check();

        return new JsonResponse(
            $result,
            'ok' === $result['status'] ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
            ['Cache-Control' => 'no-store'],
        );
    }
}
