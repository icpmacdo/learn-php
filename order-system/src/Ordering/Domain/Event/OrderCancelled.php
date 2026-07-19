<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Event;

use App\Shared\Domain\DomainEvent;

/**
 * Published language: the order was cancelled — either while placed (free)
 * or while paid inside the 24h refund window. Inventory releases the
 * reservation on it. hadBeenPaid tells consumers whether a refund story
 * would apply (out of scope, but the fact is preserved).
 */
final class OrderCancelled implements DomainEvent
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $customerId,
        public readonly bool $hadBeenPaid,
        public readonly \DateTimeImmutable $cancelledAt,
    ) {
    }
}
