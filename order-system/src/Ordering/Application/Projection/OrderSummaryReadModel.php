<?php

declare(strict_types=1);

namespace App\Ordering\Application\Projection;

/**
 * Port: the write side of the read_order_summary projection (order history).
 * Application-defined so the projector stays vendor-free; the DBAL adapter
 * lives in Ordering's Infrastructure.
 */
interface OrderSummaryReadModel
{
    public function insert(
        string $orderId,
        string $customerId,
        string $status,
        int $totalMinor,
        string $currency,
        int $lineCount,
        \DateTimeImmutable $placedAt,
    ): void;

    public function updateStatus(string $orderId, string $status, \DateTimeImmutable $updatedAt): void;
}
