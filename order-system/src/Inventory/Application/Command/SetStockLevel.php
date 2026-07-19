<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command;

final class SetStockLevel
{
    public function __construct(
        public readonly string $sku,
        public readonly int $onHand,
    ) {
    }
}
