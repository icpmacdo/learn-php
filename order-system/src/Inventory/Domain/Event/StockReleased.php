<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Event;

use App\Shared\Domain\DomainEvent;
use App\Shared\Domain\Sku;

/** Reserved stock returned to availability (order cancelled). */
final class StockReleased implements DomainEvent
{
    public function __construct(
        public readonly Sku $sku,
        public readonly string $orderId,
        public readonly int $quantity,
        public readonly int $available,
    ) {
    }
}
