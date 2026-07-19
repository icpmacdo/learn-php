<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Event;

use App\Shared\Domain\DomainEvent;
use App\Shared\Domain\Sku;

/**
 * Warehouse stock level changed by an operator (PUT /api/stock/{sku}).
 * quantityDelta is the signed change; available is carried in the payload so
 * projectors (stage 3's read_product_list) never need a lookback query.
 */
final class StockReceived implements DomainEvent
{
    public function __construct(
        public readonly Sku $sku,
        public readonly int $quantityDelta,
        public readonly int $available,
    ) {
    }
}
