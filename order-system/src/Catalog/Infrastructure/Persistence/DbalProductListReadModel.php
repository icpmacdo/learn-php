<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence;

use App\Catalog\Application\Projection\ProductListReadModel;
use Doctrine\DBAL\Connection;

/**
 * DBAL adapter for the read_product_list projection. MySQL upserts use the
 * 8.x `AS new` row-alias syntax (the VALUES() form is deprecated). Writes
 * ride the surrounding command transaction: same connection, no autonomous
 * commit.
 */
final class DbalProductListReadModel implements ProductListReadModel
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function upsertProduct(string $sku, string $name, int $priceMinor, string $currency, bool $active): void
    {
        // Preserves `available` on duplicate: that column belongs to the
        // Inventory half of the projection and may have been written first.
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO read_product_list (sku, name, price_minor, currency, active, available, product_known)
                VALUES (:sku, :name, :priceMinor, :currency, :active, 0, 1) AS new
                ON DUPLICATE KEY UPDATE
                    name = new.name, price_minor = new.price_minor, currency = new.currency,
                    active = new.active, product_known = 1
                SQL,
            ['sku' => $sku, 'name' => $name, 'priceMinor' => $priceMinor, 'currency' => $currency, 'active' => (int) $active],
        );
    }

    public function setName(string $sku, string $name): void
    {
        $this->connection->executeStatement(
            'UPDATE read_product_list SET name = :name WHERE sku = :sku',
            ['sku' => $sku, 'name' => $name],
        );
    }

    public function setPrice(string $sku, int $priceMinor, string $currency): void
    {
        $this->connection->executeStatement(
            'UPDATE read_product_list SET price_minor = :priceMinor, currency = :currency WHERE sku = :sku',
            ['sku' => $sku, 'priceMinor' => $priceMinor, 'currency' => $currency],
        );
    }

    public function setActive(string $sku, bool $active): void
    {
        $this->connection->executeStatement(
            'UPDATE read_product_list SET active = :active WHERE sku = :sku',
            ['sku' => $sku, 'active' => (int) $active],
        );
    }

    public function upsertAvailability(string $sku, int $available): void
    {
        // A stock event for a SKU the catalog has not announced yet creates
        // a hidden placeholder row (product_known = 0): the availability is
        // kept until ProductAdded flips the row visible.
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO read_product_list (sku, name, price_minor, currency, active, available, product_known)
                VALUES (:sku, '', 0, '', 0, :available, 0) AS new
                ON DUPLICATE KEY UPDATE available = new.available
                SQL,
            ['sku' => $sku, 'available' => $available],
        );
    }
}
