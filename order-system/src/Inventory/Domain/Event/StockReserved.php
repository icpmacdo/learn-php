<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Event;

use App\Shared\Domain\DomainEvent;
use App\Shared\Domain\Sku;

/**
 * Stock held against a placed order. The order id crosses the context
 * boundary as a plain string — Inventory does not import Ordering's OrderId.
 */
final class StockReserved implements DomainEvent
{
    public function __construct(
        public readonly Sku $sku,
        public readonly string $orderId,
        public readonly int $quantity,
        public readonly int $available,
    ) {
    }
}
