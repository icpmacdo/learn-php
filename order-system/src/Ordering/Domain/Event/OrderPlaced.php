<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Event;

use App\Shared\Domain\DomainEvent;

/**
 * Published language: a cart became a placed order. THE load-bearing event of
 * the whole system — Inventory reserves stock on it (same transaction: an
 * InsufficientStock in the subscriber rolls the checkout back), Notification
 * logs the confirmation (stage 3), the order-summary projector writes the
 * history row (stage 3).
 *
 * Payloads are scalars on purpose: events are the published language between
 * contexts, and consumers must not need the publisher's value objects
 * (deptrac allows them SharedDomain + this Event layer, nothing else — and
 * OrderId lives outside the Event layer by design).
 */
final class OrderPlaced implements DomainEvent
{
    /**
     * @param list<array{sku: string, name: string, quantity: int, unitPriceMinor: int, currency: string}> $lines
     */
    public function __construct(
        public readonly string $orderId,
        public readonly string $customerId,
        public readonly array $lines,
        public readonly int $totalMinor,
        public readonly string $currency,
        public readonly \DateTimeImmutable $placedAt,
    ) {
    }
}
