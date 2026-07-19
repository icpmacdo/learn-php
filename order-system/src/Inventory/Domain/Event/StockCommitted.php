<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Event;

use App\Shared\Domain\DomainEvent;
use App\Shared\Domain\Sku;

/** Reserved stock left the building (order shipped): on-hand AND reserved drop. */
final class StockCommitted implements DomainEvent
{
    public function __construct(
        public readonly Sku $sku,
        public readonly string $orderId,
        public readonly int $quantity,
        public readonly int $available,
    ) {
    }
}
