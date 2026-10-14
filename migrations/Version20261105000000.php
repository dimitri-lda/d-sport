<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index order_items.order_id and check the orders tax breakdown consistency.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'This migration requires PostgreSQL.',
        );

        // Postgres does not index foreign keys; the admin list runs a
        // correlated count(*) per order and order_items cascade on delete.
        $this->addSql('CREATE INDEX order_items_order_idx ON order_items (order_id)');

        // total = subtotal + delivery is already enforced; the tax breakdown
        // was not. NULL means "not yet computed" and stays unconstrained.
        $this->addSql(
            'ALTER TABLE orders ADD CONSTRAINT orders_tax_breakdown_check'
            .' CHECK (net_subtotal_cents IS NULL OR tax_cents IS NULL'
            .' OR net_subtotal_cents + tax_cents = subtotal_cents)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'This migration requires PostgreSQL.',
        );
        $this->addSql('ALTER TABLE orders DROP CONSTRAINT orders_tax_breakdown_check');
        $this->addSql('DROP INDEX order_items_order_idx');
    }
}
