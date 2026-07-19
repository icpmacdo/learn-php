<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Event;

use App\Shared\Domain\DomainEvent;
use App\Shared\Domain\Money;
use App\Shared\Domain\Sku;

/**
 * Published language: a product became sellable. Consumed (stage 3) by the
 * product-list projector to insert a read_product_list row.
 */
final class ProductAdded implements DomainEvent
{
    public function __construct(
        public readonly Sku $sku,
        public readonly string $name,
        public readonly Money $price,
    ) {
    }
}
