<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Event;

use App\Shared\Domain\DomainEvent;

/**
 * Published language: the gateway approved the charge and the order moved
 * placed -> paid. Consumed (stage 3) by Notification's payment receipt and
 * the order-summary projector.
 */
final class OrderPaid implements DomainEvent
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $customerId,
        public readonly string $transactionId,
        public readonly int $totalMinor,
        public readonly string $currency,
        public readonly \DateTimeImmutable $paidAt,
    ) {
    }
}
