<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

use App\Shared\Domain\Money;

/** One cart line as the customer sees it: quantities from the cart, name and price live from the catalog. */
final class CartLineView
{
    public function __construct(
        public readonly string $sku,
        public readonly string $name,
        public readonly Money $unitPrice,
        public readonly int $quantity,
        public readonly Money $lineTotal,
    ) {
    }
}
