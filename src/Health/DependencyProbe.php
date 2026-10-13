<?php

declare(strict_types=1);

namespace App\Health;

interface DependencyProbe
{
    /** @throws \Throwable when the dependency is unavailable */
    public function check(): void;
}
