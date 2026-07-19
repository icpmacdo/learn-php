<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Port;

use App\Shared\Domain\Money;
use App\Shared\Domain\Sku;

/**
 * Ordering-owned picture of a sellable product AT THIS INSTANT: what the
 * cart shows and what checkout freezes into OrderLines. Not Catalog's
 * Product — Ordering needs exactly a name and a price, so that is all this
 * carries.
 */
final class ProductSnapshot
{
    public function __construct(
        public readonly Sku $sku,
        public readonly string $name,
        public readonly Money $unitPrice,
    ) {
    }
}
