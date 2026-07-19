<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Event;

use App\Shared\Domain\DomainEvent;
use App\Shared\Domain\Money;
use App\Shared\Domain\Sku;

final class ProductPriceChanged implements DomainEvent
{
    public function __construct(
        public readonly Sku $sku,
        public readonly Money $price,
    ) {
    }
}
