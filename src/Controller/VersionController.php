<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class VersionController
{
    public function __construct(
        #[Autowire(env: 'APP_NAME')] private string $applicationName,
        #[Autowire(param: 'kernel.environment')] private string $environment,
        #[Autowire(env: 'BUILD_VERSION')] private string $buildVersion,
    ) {
    }

    #[Route('/api/version', name: 'api_version', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse([
            'name' => $this->applicationName,
            'environment' => $this->environment,
            'version' => $this->buildVersion,
        ], headers: ['Cache-Control' => 'no-store']);
    }
}
