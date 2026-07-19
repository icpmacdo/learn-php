<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Shared\Domain\Sku;
use App\Shared\Domain\StateConflict;

/**
 * Decided edge (documented in the README): a product that was fine when it
 * entered the cart but is unknown/inactive/re-priced-into-another-currency
 * BY CHECKOUT TIME. StateConflict -> 409: the request was valid, the world
 * changed underneath it — the customer can remove the line and retry.
 * Checkout never silently drops a line the customer asked for.
 */
final class ProductNoLongerAvailable extends \DomainException implements StateConflict
{
    public static function forSku(Sku $sku): self
    {
        return new self(sprintf('Product %s is no longer available.', $sku->value));
    }
}
