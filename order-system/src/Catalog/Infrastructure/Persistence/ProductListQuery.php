<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;

/**
 * Read-side query for GET /api/products — served entirely from the
 * read_product_list projection.
 *
 * Until stage 3 this class held a LEFT JOIN onto inventory_stock_item: a
 * cross-context table read that deptrac could not see because it happened in
 * SQL, not in class dependencies. Now the composition of Catalog
 * (name/price/active) and Inventory (available) happens at WRITE time, in
 * the ProductListProjector, through the contexts' published events — and the
 * request-time query is a single-table SELECT. product_known = 1 filters out
 * availability-only placeholder rows for SKUs the catalog has never
 * announced (a warehouse may legally stock those).
 */
final class ProductListQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array{
     *     products: list<array{sku: string, name: string, price: array{amountMinor: int, currency: string}, active: bool, available: int}>,
     *     total: int
     * }
     */
    public function list(int $page, int $limit): array
    {
        $offset = ($page - 1) * $limit;

        /** @var list<array{sku: string, name: string, price_minor: int|string, currency: string, active: int|string, available: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT sku, name, price_minor, currency, active, available
                FROM read_product_list
                WHERE product_known = 1
                ORDER BY sku
                LIMIT :limit OFFSET :offset
                SQL,
            ['limit' => $limit, 'offset' => $offset],
            ['limit' => \Doctrine\DBAL\ParameterType::INTEGER, 'offset' => \Doctrine\DBAL\ParameterType::INTEGER],
        );

        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM read_product_list WHERE product_known = 1');

        return [
            'products' => array_map(
                static fn (array $row): array => [
                    'sku' => $row['sku'],
                    'name' => $row['name'],
                    'price' => [
                        'amountMinor' => (int) $row['price_minor'],
                        'currency' => $row['currency'],
                    ],
                    'active' => (bool) $row['active'],
                    'available' => (int) $row['available'],
                ],
                $rows,
            ),
            'total' => $total,
        ];
    }
}
