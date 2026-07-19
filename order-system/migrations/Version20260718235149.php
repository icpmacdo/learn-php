<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stage 1 tables: one per context, context-prefixed, and deliberately NO
 * foreign key between catalog_product.sku and inventory_stock_item.sku —
 * the contexts agree on the SKU value, never on each other's schema. The
 * primary key on sku doubles as the unique index behind invariant P2
 * (DuplicateSku surfaces from the DB, no racy pre-check).
 */
final class Version20260718235149 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create catalog_product and inventory_stock_item';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE catalog_product (name VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, active TINYINT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, sku VARCHAR(32) NOT NULL, price_minor INT NOT NULL, price_currency VARCHAR(3) NOT NULL, PRIMARY KEY (sku)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE inventory_stock_item (on_hand INT NOT NULL, reserved INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, sku VARCHAR(32) NOT NULL, PRIMARY KEY (sku)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE catalog_product');
        $this->addSql('DROP TABLE inventory_stock_item');
    }
}
