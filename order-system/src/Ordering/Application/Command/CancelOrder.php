<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command;

final class CancelOrder
{
    public function __construct(
        public readonly string $customerId,
        public readonly string $orderId,
    ) {
    }
}
