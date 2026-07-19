<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command;

final class ShipOrder
{
    public function __construct(
        public readonly string $orderId,
    ) {
    }
}
