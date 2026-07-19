<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Shared\Domain\Money;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\Sku;

/**
 * Input VO for Order::place(): "one line the customer is buying, priced".
 * The PlaceOrder handler builds these from cart quantities + live catalog
 * snapshots; the Order turns them into its immutable OrderLine children.
 * Exists so place() has a typed contract instead of a shape-of-arrays one.
 */
final class NewOrderLine
{
    public function __construct(
        public readonly Sku $sku,
        public readonly string $name,
        public readonly Money $unitPrice,
        public readonly Quantity $quantity,
    ) {
    }
}
