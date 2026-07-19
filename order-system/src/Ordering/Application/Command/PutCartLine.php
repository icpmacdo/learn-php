<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command;

final class PutCartLine
{
    public function __construct(
        public readonly string $customerId,
        public readonly string $sku,
        public readonly int $quantity,
    ) {
    }
}
