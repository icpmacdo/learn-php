<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence;

use App\Ordering\Application\Projection\OrderSummaryReadModel;
use Doctrine\DBAL\Connection;

/**
 * DBAL adapter for the read_order_summary projection. Plain INSERT (an
 * OrderPlaced fires exactly once per order id) and status UPDATEs; writes
 * ride the surrounding command transaction.
 */
final class DbalOrderSummaryReadModel implements OrderSummaryReadModel
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function insert(
        string $orderId,
        string $customerId,
        string $status,
        int $totalMinor,
        string $currency,
        int $lineCount,
        \DateTimeImmutable $placedAt,
    ): void {
        $this->connection->insert('read_order_summary', [
            'order_id' => $orderId,
            'customer_id' => $customerId,
            'status' => $status,
            'total_minor' => $totalMinor,
            'currency' => $currency,
            'line_count' => $lineCount,
            'placed_at' => $placedAt->format('Y-m-d H:i:s'),
            'updated_at' => $placedAt->format('Y-m-d H:i:s'),
        ]);
    }

    public function updateStatus(string $orderId, string $status, \DateTimeImmutable $updatedAt): void
    {
        $this->connection->update(
            'read_order_summary',
            ['status' => $status, 'updated_at' => $updatedAt->format('Y-m-d H:i:s')],
            ['order_id' => $orderId],
        );
    }
}
