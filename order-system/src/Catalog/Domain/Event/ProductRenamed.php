<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Event;

use App\Shared\Domain\DomainEvent;
use App\Shared\Domain\Sku;

/**
 * Published language: a product's display name changed. Consumed by the
 * product-list projector — without this event the read_product_list `name`
 * column would drift from the write model forever (ProductAdded fires only
 * once per SKU).
 */
final class ProductRenamed implements DomainEvent
{
    public function __construct(
        public readonly Sku $sku,
        public readonly string $name,
    ) {
    }
}
