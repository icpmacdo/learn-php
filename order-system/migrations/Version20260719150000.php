<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * DB backstop for invariant S1 (0 <= reserved <= on_hand): the aggregate
 * enforces it in PHP behind a locked read (SELECT ... FOR UPDATE in
 * StockItemRepository::bySkuForUpdate), and this CHECK makes MySQL reject
 * any write that would corrupt the pair anyway — the same belt-and-braces
 * pattern as R1's unique index and P2's primary key.
 */
final class Version20260719150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CHECK constraint backing invariant S1 on inventory_stock_item';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inventory_stock_item ADD CONSTRAINT chk_inventory_stock_item_s1 CHECK (reserved >= 0 AND reserved <= on_hand)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inventory_stock_item DROP CHECK chk_inventory_stock_item_s1');
    }
}
