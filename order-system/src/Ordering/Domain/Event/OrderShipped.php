<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Event;

use App\Shared\Domain\DomainEvent;

/**
 * Published language: the order left the building. Inventory commits the
 * reservation on it (onHand and reserved both drop — stock is really gone);
 * Notification logs the shipment notice (stage 3).
 */
final class OrderShipped implements DomainEvent
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $customerId,
        public readonly \DateTimeImmutable $shippedAt,
    ) {
    }
}
