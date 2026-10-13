<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards docs parity: every HTTP operation the router exposes under /api
 * must be documented in openapi/openapi.yaml, and every documented
 * operation must map to a live route — no stale or missing entries.
 */
final class OpenApiContractTest extends KernelTestCase
{
    /** Routes generated outside the api_v1_* namespace, mapped to spec ops. */
    private const EXTRA_ROUTES = [
        'health_live' => ['GET', '/health/live'],
        'health_ready' => ['GET', '/health/ready'],
        'api_version' => ['GET', '/version'],
        '_logout_api' => ['POST', '/v1/auth/logout'],
    ];

    public function testEveryApiOperationIsDocumentedInOpenApi(): void
    {
        self::bootKernel();
        $routes = self::getContainer()->get('router')->getRouteCollection();

        $actual = [];
        foreach ($routes->all() as $name => $route) {
            if (isset(self::EXTRA_ROUTES[$name])) {
                [$method, $path] = self::EXTRA_ROUTES[$name];
                $actual[$path][strtolower($method)] = $name;
                continue;
            }
            if (!str_starts_with($name, 'api_v1_')) {
                continue;
            }
            $path = $route->getPath();
            self::assertStringStartsWith('/api/', $path, $name);
            $path = substr($path, 4);
            foreach ($route->getMethods() as $method) {
                if ('HEAD' === $method) {
                    continue;
                }
                $actual[$path][strtolower($method)] = $name;
            }
        }

        /** @var array{paths?: array<string, array<string, mixed>>} $spec */
        $spec = Yaml::parseFile(\dirname(__DIR__, 2).'/openapi/openapi.yaml');
        self::assertIsArray($spec['paths'] ?? null, 'openapi.yaml must define paths.');
        $documented = [];
        foreach ($spec['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                if (\in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    $documented[$path][$method] = true;
                }
            }
        }

        foreach ($actual as $path => $methods) {
            foreach (array_keys($methods) as $method) {
                self::assertArrayHasKey(
                    $method,
                    $documented[$path] ?? [],
                    sprintf(
                        '%s %s (route %s) is missing from openapi.yaml.',
                        strtoupper($method),
                        $path,
                        $methods[$method],
                    ),
                );
            }
        }
        foreach ($documented as $path => $methods) {
            foreach (array_keys($methods) as $method) {
                self::assertArrayHasKey(
                    $method,
                    $actual[$path] ?? [],
                    sprintf('%s %s is documented in openapi.yaml but has no route.', strtoupper($method), $path),
                );
            }
        }
    }
}
