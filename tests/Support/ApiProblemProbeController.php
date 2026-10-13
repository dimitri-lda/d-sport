<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Presentation\Http\ApiProblem;

/**
 * Test-env-only probe wired via config/routes/test/api_problem.yaml; lets the
 * functional suite exercise the real ApiProblem -> JSON envelope path.
 */
final class ApiProblemProbeController
{
    public function problem(): never
    {
        throw new ApiProblem('validation_failed', 'Request validation failed.', 422, ['email' => ['This value is not a valid email address.'], 'quantity' => ['This value should be greater than 0.']]);
    }
}
