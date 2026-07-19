<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command;

final class PlaceOrder
{
    public function __construct(
        public readonly string $customerId,
    ) {
    }
}
