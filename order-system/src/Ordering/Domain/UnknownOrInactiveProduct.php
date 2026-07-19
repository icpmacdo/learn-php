<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Shared\Domain\Sku;

/**
 * Adding a cart line for a product the ProductCatalog port does not serve —
 * unknown or inactive, indistinguishable on purpose (invariant P3 is enforced
 * at Catalog's front door; Ordering only sees "not sellable"). -> 422.
 */
final class UnknownOrInactiveProduct extends \DomainException
{
    public static function forSku(Sku $sku): self
    {
        return new self('Unknown or inactive product.');
    }
}
