<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the single-use email verification nonce to users and index the admin order list sort.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'This migration requires PostgreSQL.',
        );

        // Bound into verification-link signatures; NULL until the first
        // link is issued, rotated on every successful verification.
        $this->addSql('ALTER TABLE users ADD COLUMN email_verification_nonce VARCHAR(32) DEFAULT NULL');

        // Matches the admin and customer order lists' default
        // ORDER BY created_at DESC, id DESC (scanned backwards).
        $this->addSql('CREATE INDEX orders_created_idx ON orders (created_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'This migration requires PostgreSQL.',
        );
        $this->addSql('DROP INDEX orders_created_idx');
        $this->addSql('ALTER TABLE users DROP COLUMN email_verification_nonce');
    }
}
