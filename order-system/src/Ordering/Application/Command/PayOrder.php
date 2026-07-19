<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command;

final class PayOrder
{
    public function __construct(
        public readonly string $customerId,
        public readonly string $orderId,
        public readonly string $paymentMethodToken,
    ) {
    }
}
