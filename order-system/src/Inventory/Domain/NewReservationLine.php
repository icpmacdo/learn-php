<?php

declare(strict_types=1);

namespace App\Inventory\Domain;

use App\Shared\Domain\Quantity;
use App\Shared\Domain\Sku;

/**
 * Input VO for Reservation::forOrder(): one SKU + quantity to hold. Built by
 * the OrderPlaced subscriber from the event payload's scalars.
 */
final class NewReservationLine
{
    public function __construct(
        public readonly Sku $sku,
        public readonly Quantity $quantity,
    ) {
    }
}
