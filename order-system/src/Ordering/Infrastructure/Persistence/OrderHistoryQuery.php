<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;

/**
 * Read-side query for GET /api/orders: a customer's history, newest first,
 * straight from the read_order_summary projection. No aggregates hydrated,
 * no lines loaded — the whole point of the summary row.
 */
final class OrderHistoryQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<array{id: string, status: string, total: array{amountMinor: int, currency: string}, lineCount: int, placedAt: string, updatedAt: string}>
     */
    public function forCustomer(string $customerId): array
    {
        /** @var list<array{order_id: string, status: string, total_minor: int|string, currency: string, line_count: int|string, placed_at: string, updated_at: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT order_id, status, total_minor, currency, line_count, placed_at, updated_at
                FROM read_order_summary
                WHERE customer_id = :customerId
                ORDER BY placed_at DESC, order_id DESC
                SQL,
            ['customerId' => $customerId],
        );

        return array_map(
            static fn (array $row): array => [
                'id' => $row['order_id'],
                'status' => $row['status'],
                'total' => [
                    'amountMinor' => (int) $row['total_minor'],
                    'currency' => $row['currency'],
                ],
                'lineCount' => (int) $row['line_count'],
                'placedAt' => (new \DateTimeImmutable($row['placed_at']))->format(\DateTimeInterface::ATOM),
                'updatedAt' => (new \DateTimeImmutable($row['updated_at']))->format(\DateTimeInterface::ATOM),
            ],
            $rows,
        );
    }
}
