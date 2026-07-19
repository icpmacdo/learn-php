<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Event;

use App\Shared\Domain\DomainEvent;
use App\Shared\Domain\Sku;

final class ProductReactivated implements DomainEvent
{
    public function __construct(
        public readonly Sku $sku,
    ) {
    }
}
