<?php

declare(strict_types=1);

namespace App\Health;

use Doctrine\DBAL\Connection;

final readonly class PostgresProbe implements DependencyProbe
{
    public function __construct(private Connection $connection)
    {
    }

    public function check(): void
    {
        $this->connection->executeQuery('SELECT 1')->fetchOne();
    }
}
